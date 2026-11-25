# Upgrading

How to move a project between `gruff-php` lines, what each move breaks, and how to go back.
Every break below is the one this port's own `CHANGELOG.md` records; nothing here is a plan.

## What is stable across `0.5.x`

- Rule identifiers. A released `ruleId` keeps its meaning; it is not renamed or repurposed inside a line.
- The configuration file's name and its documented keys.
- Exit codes for the documented severity gate.
- The analysis envelope's schema version, which changes only on a minor line.

## What changes in `0.6.0`

`0.6.0` is a coordinated family release: the same contract changes land in all five gruff ports together so multi-language repositories upgrade once.

### Envelopes, baselines, and SARIF

- **Machine JSON moves to `gruff.analysis.v3` and `gruff.summary.v3`.** Update JSON consumers to read `findings[].file`, `score.composite.{score,grade}`, `paths` (for ignored and missing paths), `summary.suppressedFindings` and `diff.filteredFindings`, and `extensions.php.topLevel` (for PHP-only trend, mutation, and review data). `summary --format json` now emits `gruff.summary.v3`, the analysis document minus top-level `findings`.
- **Agent hooks move from `gruff.hook.v1` to `gruff.hook.v2`.** The envelope adds two required keys: `run` (mode, scope, operands, `analysedFiles`, and applied baseline) and `suppressions` (configured sensitive exclusion rows, `[]` when empty). Ratified exits are `0` (clean), `1` (threshold met under `--fail-on`, `--fail-on-new`, or `--fail-on-diagnostics`), and `2` (could not run). Update strict schema validators; readers that inspect only v1 keys keep working (`gruff-spec/contracts/core/hook.v2.json`, ratified 2026-09-06).
- **`list-rules --format json` publishes `thresholds` as a named knob map.** Tunable rules emit `{"maxLines": 100}` or `{"threshold": 10}` without `severity` inside `thresholds` (read `defaultSeverity` instead), and untuned rules omit `thresholds`. `list-rules <ruleId> --format json` matches. `.gruff-php.yaml` keys are unchanged.
- **Baselines move to `gruff.baseline.v3` with line-free identities.** Each row stores a SHA-256 identity (tool language, rule ID, project-relative path, and symbol plus declaration ordinal, or normalised message when no symbol exists) and an accepted `count`. Rewording a rule message no longer expires symbol-bearing rows, and same-named declarations in one file stay separate. Migrate a `0.5` (`gruff.baseline.v2`) file with `--migrate-baseline`; regenerate a `gruff.baseline.v1` file with `--generate-baseline --force`.
- **Sensitive-data findings are no longer baselineable.** Generated baselines count them under `sensitive.counts` with no rows, and hand-written rows cannot hide a secret. Fix the secret or add a reason-bearing entry under `sensitiveExclusions:`.
- **SARIF adopts the same line-free `partialFingerprints.gruffFingerprint` and drops `gruffStableIdentity`.** Existing code-scanning alerts close and reopen once at this release, then stay stable across line shifts. Two same-named declarations in one file become two alerts, and sensitive-data results emit no `partialFingerprints` so secrets never get a durable external ID.

### Scoring, exit gates, and discovery

- **Normalized density scoring replaces the absolute-sum formula.** Pillar scores now use `floor + (100 - floor) / (1 + density / densityScale)` over evaluated PHP files, so project size no longer drags grades down (4x code duplication previously dropped this port's composite from `79.70` to `70.00`). Raw weights replace the retired `4x`/`5x` multipliers in `score.pillars[].penalty` and `score.topOffenders[].penalty` (one high-confidence error is `12`, not `48`).
- **Disabled static pillars leave `scoredPillars`, and empty runs emit `null` grades.** Default scans score only static pillars with at least one enabled rule, so default-off `maintainability` no longer adds a synthetic `100` row. When nothing is evaluated (an empty directory, all files unparseable, or every rule disabled), `score.composite.{score,grade}` and `score.topOffenders[].{score,grade}` are `null` (`Composite: n/a (nothing evaluated)` in text summary and in text `analyse` when files were discovered; no score line in text `analyse` when nothing was discovered; `**Grade:** n/a (n/a/100)` in Markdown; `n/a` / `not evaluated` in HTML).
- **Per-command exit gates move from `minimumSeverity:` to `failOn:`.** Pre-0.6.0 configs with a `minimumSeverity:` command map exit `2` pointing to `failOn` (`analyse`, `report`, `dashboard`) and `gruff-php migrate-config`. `minimumSeverity` remains as a scalar display floor (`advisory`, `warning`, or `error`, never `none`) that hides findings without affecting exit codes or scores (`gruff-spec/contracts/core/cli.v1.json`, ratified 2026-09-06).
- **Empty `--changed-ranges=` exits `2` instead of scanning the whole tree.** Both `analyse` and `hook` now exit `2` with one `changed-region` diagnostic and zero findings. Callers whose range calculation can return empty must skip the scan instead of passing an empty flag (the shipped `.goat-flow/hooks/gruff-code-quality.mjs` wrapper already does).
- **Default discovery follows the family fallback policy.** Non-VCS scans honour `.gitignore`, committed control metadata stays scannable, explicit supported files bypass Git and fallback ignores, lockfiles are no longer skipped by filename, `.gruff-cache` stays skipped by default even under `.gitignore`, and PHP keeps `.phpunit.cache` and `var/cache` as fallback ignores while blocking VCS internals.

### Rules, defaults, and exclusions

- **46 low-precision rules are removed (ADR-034 plus `test-quality.setup-bloat`).** Each was right less than half the time in 0.6.0 precision measurements: `dead-code.unused-private-property` (1/23), `docs.stale-param-tag` (10/37), `modernisation.constructor-promotion-candidate` (0/35), `modernisation.first-class-callable-candidate` (2/18), `modernisation.forbidden-global-access` (8/23), `modernisation.match-expression-candidate` (0/28), `modernisation.mixed-type-overuse` (5/30), `modernisation.named-argument-opportunity` (1/29), `modernisation.phpdoc-mixed-overuse` (3/35), `naming.boolean-prefix` (3/24), `naming.generic-method` (8/24), `naming.hungarian-notation` (2/23), `naming.negative-boolean` (0/22), `naming.suffix-hungarian` (1/24), `security.dependency-composer-unpinned` (1/22), `security.github-actions-risky-workflow` (8/24), `security.insecure-random` (5/25), `security.path-traversal-file-access` (2/10), `security.sensitive-data-logging` (7/25), `security.variable-include` (4/24), `security.weak-crypto` (8/25), `sensitive-data.hardcoded-env-value` (4/18), `sensitive-data.high-entropy-string` (9/25), `sensitive-data.phi-pattern` (4/9), `sensitive-data.url-credentials` (0/11), `size.average-method-length` (17/40), `test-quality.conditional-logic` (5/25), `test-quality.eager-test` (0/25), `test-quality.excessive-mocking` (9/25), `test-quality.extends-production-class` (0/22), `test-quality.magic-number-assertion` (0/25), `test-quality.mock-only-test` (0/25), `test-quality.mock-without-expectation` (0/25), `test-quality.multiple-aaa-cycles` (0/25), `test-quality.mystery-guest` (6/23), `test-quality.setup-bloat` (7/25), `test-quality.skipped-without-reason` (3/25), `test-quality.sleep-in-test` (6/25), `test-quality.tautological-type-assertion` (12/25), `test-quality.test-method-too-long` (0/25), `test-quality.trivial-assertion` (10/25), `test-quality.unused-mock` (5/13), `waste.empty-class` (0/25), `waste.one-line-method` (0/23), `waste.redundant-variable` (3/25), and `waste.unused-parameter` (6/23). The lockfile skip (`builtInLockfile` audit rows) left with `sensitive-data.high-entropy-string`.
  - Run `gruff-php migrate-config` to strip retired IDs from `rules:` and `selection` automatically (or regenerate with `gruff-php init --force`). Leftover `rules:` entries warn and are ignored; `sensitiveExclusions`, `selection.excludeRules`, or `--exclude-rule` naming a retired ID exit `2`. Baseline rows for them resolve as `absent`.
- **18 rules move off by default (opt-in, ADR-034).** `complexity.maintainability-index` (8/14), `complexity.halstead-volume` (10/18), `dead-code.unused-private-method` (4/38), `security.dangerous-function-call` (3/6), `security.debug-mode-enabled` (3 left), `security.dependency-composer-path` (0/2), `security.dependency-composer-vcs` (0/2 actionable), `security.disabled-ssl-verification` (1/1, rest unresolved), `security.header-injection` (9 left), `security.silent-catch` (13/25), `security.sql-concatenation` (4/11), `security.unsafe-unserialize` (2/8), `sensitive-data.database-url-password` (0/9), `sensitive-data.pii-test-fixture` (35/47), `test-quality.loop-assertion-without-message`, `test-quality.no-assertions`, `test-quality.sut-not-called`, and `test-quality.test-longer-than-sut` ship opt-in. Set `rules.<id>.enabled: true` to keep any of them active; configs created by an earlier `gruff-php init` preserve explicit `enabled: true` overrides. Seven rules also recalibrate their confidence (`CHANGELOG.md` lists each change).
- **`docs.missing-public-phpdoc` is renamed `docs.missing-phpdoc`.** Reports, SARIF, and new baselines emit the new ID. Config files, CLI flags (`--include-rule`, `--exclude-rule`, `--show-rule`, `--hide-rule`), and `--migrate-baseline` still accept the old name; update any downstream consumer that filters reports by rule ID.
- **`docs.param-comment` and wider callable checks (`everyCallable: true`) are on by default.** `docs.param-comment` checks `@param` descriptions on documented declarations (`everyCallable: false`), `docs.missing-phpdoc` checks named functions (and states which parameters, returns, and exceptions are missing), `docs.missing-param-tag` and `docs.return-comment` cover closures and arrow functions, and tags-only docblocks report once via `docs.bare-phpdoc-tags`. Set `rules.docs.param-comment.enabled: false` or `rules.<id>.options.everyCallable: false` to retain the older scope.
- **Security and sensitive-data rules skip test, fixture, and example files.** Every security rule and every sensitive-data rule except `sensitive-data.pii-test-fixture` skips `test`, `tests`, `__tests__`, `spec`, `testdata`, `fixtures`, and `examples` paths and test filenames, recording each skip as a `source: "built-in"` audit row. A real vulnerability or credential committed under those paths is no longer reported.
- **Precision repairs move finding counts on surviving rules.** `docs.missing-return-tag` and `docs.missing-param-tag` skip tags declared by inherited contracts, and `security.dangerous-function-call` trusts proved closures, invokable objects, guards, and callable docblocks. Conversely, `security.dangerous-function-call` stops trusting callables proven only in another function or derived from request input (unless last bound to a closure literal), so some real dynamic calls reappear; secret rules require placeholder words (`test`, `example`, etc.) to start a token while suppressing the 20 vendor-documented sample credentials (including AWS's two example keys).

## Upgrade workflow (`0.5.x` → `0.6.0`)

1. Read the list above and decide which breaks touch your project. A project with no committed
   baseline and no hand-written configuration is usually unaffected by all but the rule changes.
2. Upgrade the package:

   ```bash
   composer require --dev blundergoat/gruff-php:^0.6
   ```

3. For an existing 0.5 config, run `gruff-php migrate-config --config .gruff-php.yaml --output .gruff-php.0.6.yaml`
   (or `--dry-run` first). Migration moves legacy `minimumSeverity` command maps to `failOn`,
   drops `allowlists.secretPreviews`, removes verified retired rule IDs across `rules:` and
   `selection`, renames `docs.missing-public-phpdoc` to `docs.missing-phpdoc`, and leaves the
   original file untouched. Review the migrated file and move it into place, or run
   `gruff-php init --force` to regenerate registry defaults while preserving `paths.ignore` and
   valid `failOn` settings.
4. Carry a `0.5` (`gruff.baseline.v2`) baseline forward rather than regenerating it, so previously
   reviewed findings stay reviewed:

   ```bash
   vendor/bin/gruff-php analyse --migrate-baseline gruff-baseline.json --generate-baseline gruff-baseline.0.6.json
   ```

   For an older `gruff.baseline.v1` baseline, regenerate in place with
   `vendor/bin/gruff-php analyse --generate-baseline gruff-baseline.json --force`.
5. Re-run `gruff-php summary .` and compare the finding count with the one you had. A rule
   whose default changed will move it; a rule whose identity changed will not.

## Limitations

- A baseline generated before `0.6.0` cannot be read directly. Migrate it; do not hand-edit it.
- Sensitive-data findings are not baselineable in `0.6.0`. A project that had suppressed them through
  a baseline needs a reason-bearing configuration exclusion instead.
- Identities change once, at this release. A finding you had already reviewed will look new until the
  migration has run.

## Retreat

If the upgrade costs more than it is worth today, pin the previous line and come back to it:

```bash
composer require --dev blundergoat/gruff-php:0.5.*
```

Keep the pre-upgrade baseline file. It stays readable by the line that produced it, and the migration
command reads it whenever you return.

## Reporting an upgrade regression

Open an issue at <https://github.com/blundergoat/gruff-php/issues> with the version you moved
from, the version you moved to, the command you ran, and the finding that changed. A finding that
moved without a break above it is a regression rather than an upgrade cost.

# Upgrading

How to move a project between `gruff-php` lines, what each move breaks, and how to go back.
Every break below is the one this port's own `CHANGELOG.md` records; nothing here is a plan.

## What is stable across `0.5.x`

- Rule identifiers. A released `ruleId` keeps its meaning; it is not renamed or repurposed inside a line.
- The configuration file's name and its documented keys.
- Exit codes for the documented severity gate.
- The analysis envelope's schema version, which changes only on a minor line.

## What changes in `0.6.0`

`0.6.0` is a coordinated family release: the same break lands in all five ports rather than one at a
time, so a project using more than one of them moves once. This port's recorded breaks are:

1. **baselines move to the family `gruff.baseline.v3` file, and every finding identity changes once** — A baseline row now stores one line-free identity and a count: sha256 over the tool language, native rule id, project-relative path, and a subject that is the symbol plus its declaration ordinal, or, when no symbol is named, the message with its measured values normalised so a file that grows keeps its reviewed identity. The rest of this entry is in `CHANGELOG.md`.
2. **sensitive-data findings can no longer be baselined** — A generated baseline counts them by rule under `sensitive.counts` and stores no row, path, or message for them, and a hand-written row cannot hide one: a secret stays visible and blocking until it is fixed or excluded with a reason under `sensitiveExclusions`.
3. **SARIF `partialFingerprints.gruffFingerprint` is the ratified identity, and a secret carries none** — Code scanning grouped alerts by the line-bearing fingerprint, so an alert closed and reopened every time code moved above it. It is now the same durable identity baseline matching reads: every existing alert closes and reopens once at this break, and each one then survives an ordinary edit. Two same-named declarations in one file, previously one alert, become two. The rest of this entry is in `CHANGELOG.md`.
4. **SARIF results no longer carry `gruffStableIdentity`** — It was a php-only second line-free digest beside the fingerprint. The fingerprint is now itself line-free and ratified for the family, so the extra key published a different name for the same purpose.
5. **every score changes - the family adopts one normalized scoring formula** — A pillar is now `floor + (100 - floor) / (1 + density / densityScale)`, where `density` is the pillar's summed severity-by-confidence weight divided by the number of PHP files that were evaluated. Scores no longer track project size: duplicating a project leaves its grade unchanged, where before it fell - a 4x duplication of identical code moved this port's composite from 79.70 to 70.00. The rest of this entry is in `CHANGELOG.md`.
6. **the composite can be null, and so can a pillar or file grade** — `score.composite.{score,grade}` are `null` when the run evaluated nothing at all: an empty directory, or one whose every PHP file failed to parse, previously reported a perfect `100` and grade `A`. `score.topOffenders[].{score,grade}` are nullable for the same reason. `summary --format text` renders `Composite: n/a (nothing evaluated)`, `analyse --format markdown` renders `**Grade:** n/a (n/a/100)`, and `analyse --format html` stamps `n/a`. `analyse --format text` splits on the two cases: it renders `Composite: n/a (nothing evaluated)` when files were discovered and none could be evaluated, and prints no score line at all when nothing was discovered.
7. **`score.pillars[].penalty` and `score.topOffenders[].penalty` are raw weights** — They were the summed weight multiplied by 4 for a pillar and 5 for a file; both multipliers belonged to the retired absolute-sum formula and are gone. A pillar that reported one high-confidence error now publishes `penalty: 12`, not `48`.
8. **machine JSON adopts the family v3 envelopes** — Update analysis consumers to accept `gruff.analysis.v3`, use one `findings[].file` path, read `score.composite.{score,grade}`, move ignore evidence under `paths`, read changed-region counts from `summary.suppressedFindings` and `diff.filteredFindings`, and read PHP-only trend, mutation, and review data from `extensions.php.topLevel`. The rest of this entry is in `CHANGELOG.md`.
9. **default scans use the family fallback policy** — Non-VCS fallbacks now defer to any governing `.gitignore`, committed control metadata stays scannable, and explicit supported files bypass Git and fallback exclusions. PHP retains `.gruff-cache`, `.phpunit.cache`, and `var/cache`; eligible lockfiles are no longer dropped by filename, while VCS internals remain blocked.

10. **the per-command exit gate moves from `minimumSeverity:` to `failOn:`** — A `0.5` config carrying the per-command `minimumSeverity:` map is refused at load time with exit `2`, and the message names `failOn` as the key that gates the exit code. `failOn` accepts `analyse`, `report` and `dashboard`. `minimumSeverity` still loads, but only as a scalar display floor that hides findings below one severity without changing the exit code or the score, and it takes `advisory`, `warning` or `error` rather than `none`. This is the ratified family contract (`gruff-spec/contracts/core/cli.v1.json`, ratified 2026-09-06: "minimumSeverity is display, failOn is the gate"). Recover with `gruff-php migrate-config`, which renames the key and leaves the original file untouched.

11. **the agent-hook contract moves from `gruff.hook.v1` to `gruff.hook.v2`** — The payload's `contractVersion` changes and the envelope gains two required keys, `run` and `suppressions`. `run` carries the audit data a consumer needs to trust the verdict — mode, scope, the operands as given, `analysedFiles`, and the applied baseline — and `suppressions` carries one row per configured sensitive exclusion the run applied, `[]` when none are configured. The exits are ratified as three and no others: `0` when nothing reached the gate, `1` when something did under an explicit consumer request (`--fail-on`, `--fail-on-new`, or `--fail-on-diagnostics`), and `2` when the run could not happen. Update any consumer that validates the payload's key set; one that reads only the keys it needs is unaffected. The contract is `gruff-spec/contracts/core/hook.v2.json`, ratified 2026-09-06.

12. **`list-rules --format json` publishes thresholds as a named knob map** — A tunable rule's `thresholds` is `{"maxLines": 100}` or `{"threshold": 10}`, never the `{"threshold": N, "severity": S}` pair, and a rule with no threshold omits the key instead of publishing `{}`. Update any consumer that read `thresholds.threshold` or `thresholds.severity`: the number sits under its knob name, the severity is the row's `defaultSeverity`, and `list-rules <ruleId> --format json` changes the same way. `.gruff-php.yaml` keys are unchanged.

13. **precision repairs move finding counts and one remediation label** — Six rules report fewer findings. `docs.missing-return-tag` and `docs.missing-param-tag` skip what an inherited contract already declares; `modernisation.named-argument-opportunity` skips PHP's variadic built-ins and callees it cannot resolve; `waste.one-line-method` skips a `parent::` delegation, a call only inside an assignment's subscript, and an inherited contract; `security.dangerous-function-call` accepts a closure, an invokable object, a guard, or a docblock that proves a callable; and `sensitive-data.high-entropy-string` skips pure-hex literals at any `entropy`. Two changes report more. `security.dangerous-function-call` no longer trusts a callable name proven only in another function, or a callee that carries request input unless it was last bound to a closure literal, so some real dynamic calls reappear. AWS's documented example key now reports under `sensitive-data.aws-access-key`, because that rule matches whole alphanumeric runs and a placeholder word must begin a token. `waste.one-line-method` findings also carry `remediationAction: CONSIDER` instead of `APPLY`, so an agent that auto-applies `APPLY` findings no longer inlines them; that label change moves no count.

## Upgrade workflow (`0.5.x` → `0.6.0`)

1. Read the list above and decide which breaks touch your project. A project with no committed
   baseline and no hand-written configuration is usually unaffected by all but the rule changes.
2. Upgrade the package:

   ```bash
   composer require --dev blundergoat/gruff-php:^0.6
   ```

3. Migrate the configuration if you hand-wrote one. `gruff-php migrate-config` rewrites it for
   the current schema and carries your tuning forward, never modifying the file it reads: pass
   the old file to `--config` and the new one to `--output`. `gruff-php init --force` is not the
   upgrade path — it writes registry defaults and preserves only `paths.ignore`.
4. Carry a baseline forward rather than regenerating it, so previously reviewed findings stay
   reviewed. The command is in the CHANGELOG entry for the baseline break.
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

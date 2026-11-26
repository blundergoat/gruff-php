# Changelog

Notable user-facing changes to `gruff-php` are listed here.

## 0.6.0 - 2026-11-27

Upgrading from `0.5.x`: this release updates every machine-readable contract at once so all five gruff ports ship one shared shape. [`UPGRADING.md`](UPGRADING.md) walks through each migration command and the retreat path (pin `0.5.*` and keep your pre-upgrade config and baseline files, which `0.5` still reads).

### Breaking changes

- **BREAKING: machine JSON moves to `gruff.analysis.v3` and `gruff.summary.v3`** - Update analysis consumers to read `findings[].file`, `score.composite.{score,grade}`, `paths` (for ignored and missing paths), `summary.suppressedFindings` and `diff.filteredFindings` (for changed-region counts), and `extensions.php.topLevel` (for PHP-only trend, mutation, and branch-review data). `summary --format json` now emits `gruff.summary.v3`, the analysis envelope with only top-level `findings` removed. `analyse` JSON also exposes `applied`, `unchangedFindings`, and `resolvedFindings` in the canonical `baseline` object. There is no v2 writer or deprecation window so every port moves in lockstep.
- **BREAKING: agent hooks move from `gruff.hook.v1` to `gruff.hook.v2`** - The payload adds two required keys: `run` (mode, scope, operands, `analysedFiles`, and applied baseline) and `suppressions` (one row per configured sensitive exclusion). Ratified exits are `0` (nothing met the gate), `1` (findings met `--fail-on`, `--fail-on-new`, or `--fail-on-diagnostics`), and `2` (the run could not complete). Consumers that read only the v1 keys keep working; strict schema validators must accept the two new keys (`gruff-spec/contracts/core/hook.v2.json`, ratified 2026-09-06).
- **BREAKING: baselines move to `gruff.baseline.v3` and SARIF adopts the same line-free fingerprint** - Each baseline row stores a line-free SHA-256 identity (tool language, rule ID, project-relative path, and symbol plus declaration ordinal, or normalised message when no symbol exists) alongside an accepted `count`. Rewording a rule message no longer expires a symbol-bearing entry, same-named declarations in one file stay distinct (fixing variable occurrences such as `$value#1` and `$value#2` in both baselines and SARIF), and occurrences above the reviewed count report as new.
  - Carry a `0.5` (`gruff.baseline.v2`) baseline forward once with `gruff-php analyse --migrate-baseline <old> --generate-baseline <new>`. The original file is never modified; pointing output at the input (by path, symlink, or hard link), passing a multi-container `0.5` file, or omitting `--generate-baseline` exits `2`.
  - A `gruff.baseline.v1` file cannot be migrated; regenerate it with `gruff-php analyse --generate-baseline <path> --force`. Writing to the default `gruff-baseline.json` refuses to overwrite a non-v3 baseline without `--force`, and baselines written by another gruff port fail with `baseline-error`.
  - Collisions remain visible with a `baseline-collision` diagnostic (and `collision`, `notEligible`, and `sensitiveCounted` buckets under `baseline.extensions.php.baseline.buckets`). A symbol containing `#` emits no identity instead of aborting.
  - Sensitive-data findings are never baselineable: generated baselines record counts under `sensitive.counts` with no rows, and hand-written rows cannot hide a secret. Use `sensitiveExclusions:` instead.
  - SARIF `partialFingerprints.gruffFingerprint` adopts this same line-free identity (existing code-scanning alerts close and reopen once, then survive line shifts), `gruffStableIdentity` is removed, and sensitive-data findings emit no `partialFingerprints` so secrets never get a durable external ID.
- **BREAKING: normalized density scoring, nullable grades, raw penalties, and enabled-only static pillars** - Pillar scores now use the family curve `floor + (100 - floor) / (1 + density / densityScale)` over evaluated PHP files, so project size no longer drags grades down (duplicating a codebase 4x previously dropped this port's composite from `79.70` to `70.00`; it now holds steady). Grade bands stay `A>=90`, `B>=80`, `C>=70`, `D>=60`, and per-file scores follow the same curve.
  - `analyse`, `summary`, and branch review score only static pillars with at least one enabled rule, so default-off `complexity.maintainability-index` no longer adds a synthetic `100` `maintainability` row. Disabling every rule, or scanning an empty or unparseable tree, emits `null` for `score.composite.{score,grade}` and `score.topOffenders[].{score,grade}` (`Composite: n/a (nothing evaluated)` in text, `**Grade:** n/a (n/a/100)` in Markdown, `not evaluated` in HTML, no score line in `analyse --format text` when zero files were discovered, and no trend entry written to `--history-file`).
  - `score.pillars[].penalty` and `score.topOffenders[].penalty` now report raw weighted sums without the retired `4x` and `5x` multipliers (one high-confidence error is `12`, not `48`). `score.evaluatedFiles` and `score.scoredPillars` are published so consumers can reproduce the composite.
- **BREAKING: exit gates move from `minimumSeverity:` to `failOn:`** - Pre-0.6.0 configs carrying a per-command `minimumSeverity:` map exit `2` pointing to `failOn` and `gruff-php migrate-config`. `failOn` accepts `analyse`, `report`, and `dashboard`; `minimumSeverity` stays as a scalar display floor (`advisory`, `warning`, or `error`, never `none`) that hides lower-severity findings without changing exit codes or scores (`gruff-spec/contracts/core/cli.v1.json`, ratified 2026-09-06).
- **BREAKING: `list-rules --format json` publishes `thresholds` as a named knob map** - Tunable rules emit `{"maxLines": 100}` or `{"threshold": 10}` without duplicating `severity` (already in `defaultSeverity`), and untuned rules omit `thresholds` instead of emitting `{}`. `list-rules <ruleId> --format json` matches. `.gruff-php.yaml` keys are unchanged.
- **BREAKING: size and complexity findings split into two limit bands** - Across `size.file-length`, `size.class-length`, `size.method-length`, `size.parameter-count`, `size.property-count`, `size.public-method-count`, `complexity.cognitive`, `complexity.cyclomatic`, and `complexity.nesting-depth`, a unit between `1x` and `1.5x` its limit reports as an advisory notice (`limitBand: lower`, asking not to add to it), while `1.5x` and above keeps its configured severity (`limitBand: upper`, asking to split or simplify) (FAMILY-CONTRACT.md section 12). Messages stay unchanged so baselines keep matching. `--fail-on warning` and `--fail-on error` stop failing on lower-band notices; the default `advisory` gate still fails. Complexity advice no longer tells readers to extract conditions or nested logic.
- **BREAKING: 46 low-precision rules are removed** - Precision measurements on real PHP corpora found each rule right less than half the time, so they are retired rather than tuned (ADR-034, plus `test-quality.setup-bloat`):
  - **Dead code, docs, size, waste (7):** `dead-code.unused-private-property` (1/23), `docs.stale-param-tag` (10/37), `size.average-method-length` (17/40), `waste.empty-class` (0/25), `waste.one-line-method` (0/23), `waste.redundant-variable` (3/25), `waste.unused-parameter` (6/23).
  - **Modernisation and naming (12):** `modernisation.constructor-promotion-candidate` (0/35), `modernisation.first-class-callable-candidate` (2/18), `modernisation.forbidden-global-access` (8/23), `modernisation.match-expression-candidate` (0/28), `modernisation.mixed-type-overuse` (5/30), `modernisation.named-argument-opportunity` (1/29), `modernisation.phpdoc-mixed-overuse` (3/35), `naming.boolean-prefix` (3/24), `naming.generic-method` (8/24), `naming.hungarian-notation` (2/23), `naming.negative-boolean` (0/22), `naming.suffix-hungarian` (1/24).
  - **Security and sensitive data (11):** `security.dependency-composer-unpinned` (1/22), `security.github-actions-risky-workflow` (8/24), `security.insecure-random` (5/25), `security.path-traversal-file-access` (2/10), `security.sensitive-data-logging` (7/25), `security.variable-include` (4/24), `security.weak-crypto` (8/25), `sensitive-data.hardcoded-env-value` (4/18), `sensitive-data.high-entropy-string` (9/25), `sensitive-data.phi-pattern` (4/9), `sensitive-data.url-credentials` (0/11). The built-in lockfile skip (`builtInLockfile` audit rows) leaves with `sensitive-data.high-entropy-string` (FAMILY-CONTRACT.md sections 12 and 13a now bind `gruff-rs` alone).
  - **Test quality (16):** `test-quality.conditional-logic` (5/25), `test-quality.eager-test` (0/25), `test-quality.excessive-mocking` (9/25), `test-quality.extends-production-class` (0/22), `test-quality.magic-number-assertion` (0/25), `test-quality.mock-only-test` (0/25), `test-quality.mock-without-expectation` (0/25), `test-quality.multiple-aaa-cycles` (0/25), `test-quality.mystery-guest` (6/23), `test-quality.setup-bloat` (7/25), `test-quality.skipped-without-reason` (3/25), `test-quality.sleep-in-test` (6/25), `test-quality.tautological-type-assertion` (12/25), `test-quality.test-method-too-long` (0/25), `test-quality.trivial-assertion` (10/25), `test-quality.unused-mock` (5/13).
  - **Migration:** Run `gruff-php migrate-config` to strip retired IDs from `rules:` and `selection`. Leftover `rules:` blocks warn and are ignored; `sensitiveExclusions`, `selection.excludeRules`, or `--exclude-rule` naming a retired ID exit `2`. Baseline rows for retired rules resolve as `absent`; regenerate or migrate the baseline to drop them.
- **BREAKING: 18 rules move off by default (opt-in)** - Each rule either lacked enough findings to prove its precision floor or scored below it on unseen corpora (ADR-034): `complexity.maintainability-index` (8/14), `complexity.halstead-volume` (10/18), `dead-code.unused-private-method` (4/38), `security.dangerous-function-call` (3/6), `security.debug-mode-enabled` (3 remaining after repair), `security.dependency-composer-path` (0/2), `security.dependency-composer-vcs` (0/2 actionable), `security.disabled-ssl-verification` (1/1, rest unresolved), `security.header-injection` (9 remaining after repair), `security.silent-catch` (13/25 after repair), `security.sql-concatenation` (4/11), `security.unsafe-unserialize` (2/8), `sensitive-data.database-url-password` (0/9), `sensitive-data.pii-test-fixture` (35/47 after repair), `test-quality.loop-assertion-without-message` (<70% floor), `test-quality.no-assertions` (<70% floor), `test-quality.sut-not-called` (28/50 unresolved), and `test-quality.test-longer-than-sut` (50/50 unresolved). Set `rules.<id>.enabled: true` to enable any of them; configs written by an earlier `gruff-php init` keep existing explicit `enabled: true` settings on.
- **BREAKING: 7 rules recalibrate confidence to measured precision** - Confidence follows measured error-adjusted precision (`high` >= `0.85`, `medium` >= `0.70`, `low` below): `complexity.cognitive` (high -> low), `complexity.maintainability-index` (medium -> low), `docs.bare-phpdoc-tags` (medium -> high), `docs.missing-property-phpdoc` (medium -> high), `naming.class-file-mismatch` (high -> medium), `naming.identifier-quality` (medium -> low), and `security.error-suppression` (high -> medium). All 4 rules dropping from `high` now publish reviewed `falsePositiveShapes`; unmeasured rules keep their confidence and are listed under Confidence evidence in `docs/rules.md`.
- **BREAKING: `docs.missing-public-phpdoc` is renamed `docs.missing-phpdoc`** - The rule checks every method regardless of visibility, so the old name was misleading. Reports, SARIF, and new baselines emit `docs.missing-phpdoc`; the old ID still resolves in config blocks, `selection`, CLI flags (`--include-rule`, `--exclude-rule`, `--show-rule`, `--hide-rule`), and `--migrate-baseline`, and `gruff-php migrate-config` renames it automatically.
- **BREAKING: `docs.param-comment` and wider callable checks (`everyCallable: true`) are enabled by default** - `docs.param-comment` now checks `@param` descriptions on documented declarations (`everyCallable: false`), `docs.missing-phpdoc` checks named functions, and `docs.missing-param-tag` and `docs.return-comment` check bound closures and arrow functions. Set `rules.docs.param-comment.enabled: false` or `rules.<id>.options.everyCallable: false` to keep the earlier scope.
- **BREAKING: empty or unreadable `--changed-ranges` exits `2` with no findings** - Passing `--changed-ranges=` previously widened silently to a whole-tree scan and exited `0`, and unreadable ranges emitted inconsistent diagnostics across `analyse` and `hook`. Both surfaces now exit `2` with one `changed-region` diagnostic and zero findings. The shipped `.goat-flow/hooks/gruff-code-quality.mjs` wrapper already guards empty ranges.
- **BREAKING: default discovery uses the family fallback policy and skips vendored/built/minified files** - Non-VCS scans defer to `.gitignore`, committed control metadata stays scannable, explicit supported files bypass Git and fallback ignores, lockfiles are no longer dropped by filename, `.gruff-cache` stays skipped by default even under `.gitignore`, and PHP retains `.phpunit.cache` and `var/cache` as fallback ignores while blocking VCS internals. Directories named `vendor`, `vendors`, `third_party`, `third-party`, `bower_components`, or `dist` (any depth or case) and `.min.*` files are skipped by default even when Git-tracked (`paths.details` records `dependency`, `build-output`, or `generated`; override with `--include-ignored` or an explicit file operand, FAMILY-CONTRACT.md section 7).

### Scoring and rule precision

- **Common short English words and universal tech terms stay quiet in `naming.abbreviation-allowlist`** - Plain two- and three-letter English words (`fee`, `row`, `tax`, `day`, `end`, `map`, `sum`, `tag`, `zip`, and similar) and universal technical terms (`api`, `csv`, `env`, `ids`, `jwt`, `pdf`, `sql`, `xml`, and similar) no longer require `allowlists.acceptedAbbreviations`. Built-in exemptions remain disjoint from the 16-word `init` seed so replace-not-merge stays observable; domain terms such as `dob`, `dto`, and `utc` still report until configured.
- **All 42 `medium` and `low` confidence rules publish `falsePositiveShapes`** - `47` rules in total carry reviewed false-positive shapes paired with mitigations, published in both `list-rules --format=json` and `list-rules <rule-id>`. Rules without catalogued shapes omit the key.
- **Correlated line-less findings share one penalty** - `ScoreCalculator` now bills correlated size and complexity findings on one method as one shared penalty even without line numbers, matching the `clusters` output (affects direct calculator callers; CLI rules always attach line numbers).
- **Simpler scoring for flat dispatch, early-exit guards, attributes, and comments** - `complexity.cyclomatic` counts a `switch` or `match` as one decision (while `complexity.maintainability-index` keeps the full count and skips test/fixture/example paths); `complexity.cognitive` skips nesting penalties on early-exit guards and scores `switch` cases at the switch's level; `size.file-length` and `size.class-length` exclude attribute-only lines; `test-quality.test-longer-than-sut` excludes comments, attributes, and blank lines; and `waste.unreachable-code` ignores comments after `return`, `throw`, or `exit`.
- **Documentation rules reduce duplicate and boilerplate findings** - Tags-only docblocks report once (`docs.bare-phpdoc-tags`, not `docs.return-comment`). `docs.missing-phpdoc` adds `Also describe ...` naming only what the signature actually has. `docs.missing-return-tag` and `docs.missing-param-tag` honour inherited contracts (matching ancestors and `#[\Override]` by resolved names, with strict `@inheritdoc` parsing also applied to `docs.missing-throws-tag`). Unbroken runs of constants (`docs.missing-constant-phpdoc`, with `requirePhpdocForApiConstants` / `apiPathPatterns` emitting `groupedLocalComment: true` when an exported constant lacks its own PHPDoc) and properties (`docs.missing-property-phpdoc`, plus `options.acceptLineComments`) accept a single leading comment or docblock.
- **Naming advice points at valid fixes** - `naming.class-file-mismatch` suggests renaming a class to match its filename only when the filename is already StudlyCaps, and `naming.identifier-quality` points intentional names at `ignoredNames` instead of `placeholderNames`/`genericTokens`.
- **Security and sensitive-data detectors skip safe, escaped, and vendor-sample patterns** - Seven detectors stop flagging already-safe or explained code (`security.debug-mode-enabled`, `security.header-injection`, `security.process-command-construction`, `security.reflected-xss`, `security.silent-catch`, `sensitive-data.pii-test-fixture`, and `waste.commented-out-code`; blind re-measurement accepted three lost true positives). Escaped and literal sinks stay quiet (`escapeshellarg()` outside quotes, `(int)`, `->quoteString()`, `Util::backquote()`, WordPress `esc_html()`/`esc_attr()`/`esc_url()`, literal exec commands, underscore deployment constants such as `__SITE_ROOT__`, proved local callables, bounded local aliases, `Closure::bind()`, and typed callback collections), while request-derived callees and cross-function unproven names still report. Placeholder words (`test`, `example`, etc.) must now start a token (so `latest` and `attestation` stop hiding real secrets), `sensitive-data.aws-access-key` matches whole alphanumeric runs and ignores all-`X` masked keys, 20 vendor-documented sample credentials (stored as SHA-256 digests) stay quiet, and opt-in `sensitive-data.database-url-password` ignores stock dev credentials (`postgres:postgres`, `root:root`, `scott:tiger`) on local or single-label hosts (FAMILY-CONTRACT.md section 5).
- **Test-quality precision and grouped duplicate-test findings** - `test-quality.repeated-structure-missing-data-provider` enables `groupFindings: true` by default (one finding per group of 3+ matching tests, retaining nested literals/PHPDoc and body attribute arguments; set `groupFindings: false` for legacy output). `test-quality.no-assertions` recognises Prophecy, Symfony deprecation, Doctrine `VerifyDeprecations`, bound Laravel JSON expectations, and bounded same-class helper calls; `test-quality.loop-assertion-without-message` accepts identifying expected values, singletons, and direct exits; and `test-quality.sut-not-called` matches verb-and-object prefixes rather than lone verbs.

### Configuration, suppressions, and bounded scans

- **`gruff-php migrate-config` cleans retired rule IDs and legacy aliases** - Out-of-place migration moves pre-0.6.0 `minimumSeverity:` command maps to `failOn:`, drops `allowlists.secretPreviews`, strips 55 verified retired rule IDs across `rules:`, `selection.rules`, and `selection.excludeRules`, renames `docs.missing-public-phpdoc` to `docs.missing-phpdoc`, leaves mistyped unknown IDs untouched, and never modifies the original file.
- **New `sensitiveExclusions:` config section** - Accept a reviewed sensitive-data finding by hand with exact `rule`, project-relative `path`, optional `symbol` (no rule stamps a symbol yet, so entries with `symbol` match zero today), and a required `reason`. Wildcards, pillar names, unknown or non-sensitive rules, absolute/`..`/globbed paths, duplicates, and text/value matchers (`message_contains`, `value`, `preview`, etc.) exit `2` naming entry index and key. Every entry appears in JSON `suppressions` and text `Suppressed findings:` across both `analyse` and `summary`, removes findings from scores and exit codes, and reports `suppressed: 0` cleanly when fixed.
- **Built-in test-path skips for security and sensitive-data rules** - Every security rule and every sensitive-data rule except `sensitive-data.pii-test-fixture` skips `test`/`tests`/`__tests__`/`spec`/`testdata`/`fixtures`/`examples` paths and test filenames (`*Test.php`, etc.). **Deliberate trade-off: a real vulnerability or credential committed under those paths is no longer reported.** Every skip is audited as a `source: "built-in"` row in `suppressions` (FAMILY-CONTRACT.md section 13a).
- **Honoured inline suppression comments and exec deduplication** - `phpcs` (`WordPress.PHP.NoSilencedErrors`, `WordPress.DB.PreparedSQL`, or bare `phpcs:ignore`) and `psalm` (`ForbiddenCode`) comments on the reported line or alone on the line above suppress the matching gruff-php rule (`@phpstan-ignore` and `phpcs:disable` regions are not honoured). Exec-family calls hit by both `security.dangerous-function-call` and `security.process-command-construction` report once under `security.process-command-construction`. Both are audited as `inline`/`duplicate` rows in `suppressions`, counted in text output, and retained as suppressed SARIF results (FAMILY-CONTRACT.md section 13c).
- **Bounded deep scans protect oversized PHP files** - Files over `20,000` lines or `2,000,000` bytes run raw-text checks (file size, sensitive-data, config checks) while skipping parsing and AST work, emit a nonfatal `bounded-deep-scan` diagnostic, remain counted as analysed, and leave the scoring denominator. Tune or disable via `deepScanBudget.{enabled,maxLines,maxBytes}` or `--deep-scan-budget <lines>:<bytes>` / `off`.

### Fixes and security

- **Security:** Upgraded `symfony/filesystem` and `symfony/yaml` to `7.4.23` (PHP 8.3 LTS line) to resolve reported advisories.
- **Fixed: config-less projects no longer inherit gruff-php's own checkout config** - Running `analyse`, `summary`, `report`, `hook`, `check-ignore`, or `dashboard` from a source checkout against a repo without `.gruff-php.yaml` now uses registry defaults (matching Composer installs).
- **Fixed: confidence gates, hook safety, cache keying, and PHP 8.3 resolution** - Confidence thresholds now gate exit decisions; hook gates honour changed scopes and exit `2` on any fatal diagnostic, such as a missing operand or a file that does not parse; baseline migration caps matches to reviewed occurrence counts; inline suppressions read PHP comments only; dashboard cache fingerprints align with discovery using locked eviction; and Composer dependencies resolve cleanly on PHP 8.3+.
- **Fixed: result cache invalidates when analyser source changes** - The cache key now digests every PHP file under the analyser root (289 files, ~3.1 MB, median 2.1 ms), preventing stale cached `analyse` counts from drifting against `summary` and `--no-cache` (previously 3,255 vs 3,203 on this repository).
- **Fixed: relative CLI operands, `--config`, and baseline paths resolve from the launch directory** - `analyse`, `summary`, and `report` now resolve target operands, `--config`, `--baseline`, and `--generate-baseline` from the invocation directory rather than the resolved project root, and error envelopes for targets outside the launch directory omit the out-of-root `file` key instead of throwing an uncaught exception.
- **Fixed: text `summary` exits `2` on fatal scan errors** - Text `summary` now propagates exit code `2` when parse errors invalidate a run, matching `summary --format json` and `analyse`.

### Known limitations

- **No family regression suite for procedural SQL/shell sinks** - `security.sql-concatenation` and `security.process-command-construction` handling of procedural `mysqli_query` and `shell_exec` sinks ships without a cross-family regression suite (deferred past 0.6.0).
- **Self-scan coverage boundary** - `gruff-php` reporting zero findings on its own repository proves the self-scan invariant, not zero false positives; false-positive rates are measured by negative fixtures and corpora.

## 0.5.2 - 2026-08-16

0.5.2 counts only substantive lines in `size.file-length` and `size.class-length`, so blank and comment-only lines stop consuming a file's or class's budget, and it teaches the security rules to read named arguments, procedural SQL drivers, and `proc_open()` argument vectors. Prophecy expectations, promoted-property docs, and `summary`'s secret allowlist shed false positives, while public mutable promotions start reporting. Empty scans carry a non-fatal `empty-analysis` diagnostic and no score instead of a full-marks grade. Four rule options ship alongside self-documenting `init` output, and the agent hooks close four parser bypasses and a batch-boundary gap. No rule IDs, severities, thresholds, scoring, `gruff.analysis.v2`, or `--fail-on` behaviour changed; both size rules emit new identities and several rules gain rows, so the baseline impact is noted below.

- **File and class lengths count substantive lines** - Blank and comment-only lines are free; thresholds stay unchanged and messages name the metric.
- **Regenerate size baselines** - Accepted size findings resurface; run `vendor/bin/gruff-php analyse --generate-baseline --fail-on none`.
- **SARIF size identities change** - Both size rules emit new `gruffStableIdentity` values because their messages changed.
- **Refresh class-length hook baselines** - `size.class-length` gets a new hook identity; `size.file-length` stays matched.
- **Named arguments resolve at global sinks** - Rules read `header(header: $x)` like `header($x)`; method and constructor sinks stay positional.
- **Named guards still count as guards** - `simplexml_load_string(data: $xml, options: LIBXML_NONET)` reads as protected rather than unguarded.
- **`sqlsrv_query` accepts both parameter spellings** - Microsoft documents `tsql`, the bundled stub says `sql`; either resolves the query slot.
- **Procedural SQL sinks are covered** - Query text reaches the procedural drivers through one unambiguous same-scope assignment.
- **`proc_open()` argv arrays stay safe** - A direct argument vector is not shell text unless its first item launches a shell with a command flag.
- **Security baselines gain rows, not identities** - Existing messages and identities hold; review or baseline new procedural and named-argument rows.
- **Public-property checks cover promotion** - Readonly classes stay quiet; public mutable promotions now report and may add baseline findings.
- **Prophecy expectations stay configured** - Native promises, predictions, and asserted `reveal()` values no longer look like bare mocks.
- **Prophecy baselines shed false positives** - Obsolete groups disappear; remaining messages and `gruffStableIdentity` values stay stable.
- **Empty scans are unscored** - `analyse` emits `empty-analysis` and omits the score and branch-review delta when no PHP files are discovered.
- **Empty scans preserve exit policy** - The diagnostic is non-fatal, so zero-file runs still exit 0 without changing `--fail-on` behavior.
- **Empty-scan baselines are unchanged** - The diagnostic is not a finding, so it creates no `gruffStableIdentity` or baseline entry.
- **Promoted constructor docs stop duplicating** - Missing tags use `docs.missing-param-tag`, absent docblocks `docs.missing-public-phpdoc`.
- **Four rule options added** - Tune generic names, property line comments, dangerous functions, and intentional public-state classes.
- **Generated config explains its rules** - `init` writes each rule's description as its comment; size rules now name substantive-line counting.
- **Agent-hook parsers close bypasses** - Curl form headers, xargs flags, escaped filenames, and mixed-case env prefixes stop hiding protected paths.
- **Agent-hook batch scanning closes a gap** - Files past the first batch boundary are scanned, so a late protected path is not skipped.
- **`summary` applies the secret allowlist** - Vetted `allowlists.secretPreviews` findings no longer inflate its counts, grade, or rule table.

## 0.5.1 - 2026-07-20

0.5.1 removes the documented false-positive shapes while retaining their counterexamples: multiline regex ownership, exact named callback boundaries, clear Boolean state/proposition names, and short bounded pattern families gain conservative matching, while abbreviation and named-argument advisories gain machine-readable decision context. Rule IDs, severities, thresholds, scoring, `gruff.analysis.v2`, and `--fail-on` behaviour are unchanged. The bounded-group overflow message is deliberately more precise; its baseline impact is noted below.

- **Regex comments follow their statement** - `docs.regex-comment` supports multiline and contiguous calls; review baseline groups after upgrading.
- **Named callbacks stay named** - One-line checks exempt proven same-class callables; allow others with `options.allowedSymbols`.
- **Boolean names use word boundaries** - State suffixes and `requires` propositions pass; tune vocabularies or disable public API checks.
- **Constructor and abbreviation advice is quieter** - Named-argument advice skips constructors; `dto` and `utc` are accepted by default.
- **Findings classify remediation** - JSON, hook, and SARIF label fixes `APPLY` or `CONSIDER`; `CONFIGURE` remains reserved.
- **Pattern-family comments stay bounded** - Pattern or regex comments cover five contiguous constants; message changes may alter baselines.

## 0.5.0 - 2026-07-03

0.5.0 makes gruff's identities line-stable — baselines, branch review, and SARIF all stop churning when unrelated edits shift line numbers — sharpens eight rules against false positives and evasion gaps, brings `report` up to `analyse`'s workflow surface, and makes release version bumps drift-proof.

- **BREAKING: Baselines use `gruff.baseline.v2` groups** - Match file/rule/message counts; regenerate with `analyse --generate-baseline`.
- **BREAKING: Security profiles reject out-of-profile includes** - Remove the profile or include only security and sensitive-data rule IDs.
- **SARIF adds a stable partial fingerprint** - `gruffStableIdentity` keeps alerts open across unrelated line shifts.
- **Branch review tolerates line shifts** - Symbol-less findings use file/rule/message identity; duplicate occurrences still count separately.
- **Machine output survives invalid UTF-8** - JSON-based formats substitute bad bytes; valid finding hashes remain unchanged.
- **Security taint follows `.=` assignment** - Concatenated request data reaches shared security sinks; clean reassignment clears taint.
- **Unsafe XML loading requires an XML receiver** - Proven XML objects report; unrelated `open`, `load`, or `xml` calls stay silent.
- **Unsafe archive extraction tracks uploaded sources** - Request-controlled archives report with fixed destinations; entry names remain unproved.
- **Opaque dotted tokens no longer evade secret checks** - Only JWT-shaped literals are delegated; routes, versions, domains, and paths stay exempt.
- **Cognitive complexity counts `match`** - It scores the construct plus nested arms; affected message-keyed baselines may need regeneration.
- **Unused private methods respect dynamic dispatch** - Computed same-class calls suppress advice; foreign dynamic calls do not.
- **Throws docs stay in their own scope** - Throws in nested functions, closures, and anonymous classes no longer affect the outer method.
- **Negative Boolean names cover snake_case** - Whole-word `no_` and `not_` names report; prefixes inside words remain exempt.
- **Trend deltas compare like scopes** - Full-project and changed-scope histories stay separate; old entries remain readable.
- **`report` supports analyse workflows** - It forwards profile, changed-scope, new-finding, cache, Infection, and runtime options.
- **Version bumps update every stamp** - The bump script and preflight cover README and CLI summaries; golden tests derive the version.

## 0.4.1 - 2026-06-13

0.4.1 focuses on rule-rubric precision: fewer false positives and fewer over-severe findings without disabling the rules that catch real maintainability problems.

- **BREAKING: Removed `modernisation.enum-candidate`** - Constant-only classes no longer receive enum migration advice; findings disappear.
- **Rule rubrics are tighter** - Docs, naming, size, complexity, modernisation, and dead-code checks avoid known false-positive shapes.
- **Constant PHPDoc is configurable** - Meaningful comments pass by default; API constants can require PHPDoc globally or by path.
- **`report` rejects unknown rule IDs** - Invalid include/exclude filters exit 2 before prompting, writing config, or analysing.
- **Internal namespaces are consolidated** - Public CLI, config, schemas, and baselines stay stable; direct internal imports must be updated.

## 0.4.0 - 2026-06-11

0.4.0 retires the project rules whose whole-project analysis made per-edit feedback slow and whose verified false-positive rates made their findings untrustworthy on framework code. With no project rules left, the per-file result cache introduced in 0.3.0 now engages on every default run, and single-file scans no longer pay whole-project cost. Eight per-unit rules also gained precision fixes for mechanical misfires.

- **BEHAVIOUR CHANGE: Rule filters control execution** - Excluded rules do not run or score; unknown IDs are usage errors.
- **BREAKING: Removed `dead-code.unused-internal-{class,constant,function}`** - Delete their config; findings disappear.
- **BREAKING: Removed `design.single-implementor-interface`** - 45-100% false-positive rates: extension-point interfaces read as single-implementor.
- **Removed-rule config remains valid** - Unknown IDs under `rules:` warn and are ignored; `selection:` still rejects them.
- **Per-file cache is on by default** - Warm framework scans fell from 33–82s to 1.8–5.0s; the cap rose to 32,768 entries.
- **Single-file `analyse`/`hook` is no longer O(project)** - One-file scans dropped from 13-31s to under 0.1s on real framework repos.
- **Several rules misfire less** - Test bases, snake_case Boolean names, closure params, and superglobal writes receive intended handling.
- **Security checks exempt proven-safe shapes** - Variable includes accept fixed paths; SQL checks handle prepared queries and safe identifiers.
- **Sensitive-data checks skip safe fixtures** - Identifier literals, reserved-domain emails, and marked synthetic addresses are exempt.
- **Piped config-less runs no longer hang or write config** - The init prompt appears only in human-facing TTY output.

## 0.3.1 - 2026-06-09

0.3.1 adds the `gruff.hook.v1` agent-hook contract (`gruff-php hook --format json`) for editor and coding-agent integrations, plus one conservative test-quality rule, fixes Symfony YAML route and changed-region accounting edges in project-wide dead-code analysis, and moves the headline numbers to the top of text reports. No breaking changes; JSON schemas, config format, and baselines are unchanged.

- **Agent hooks emit `gruff.hook.v1` JSON** - Hooks report normalized, stable findings and support baseline, diff, and since comparisons.
- **Hook symbol scope is fairer** - Changed-range hooks omit file/project findings unless new against the baseline or diff base.
- **Changed symbol scope drops untouched aggregates** - Use `--changed-scope=file` to retain file/class aggregates for changed files.
- **Added `test-quality.static-analysis-redundant-test`** - Advisory findings flag tests that only restate same-file declarations.
- **Symfony YAML controllers count as live** - Internal FQCN controllers in `_controller` or `controller:` routes no longer look unused.
- **Suppression counts use changed files** - `suppressedCount` matches changed-file findings and also appears in `diff` JSON.
- **Text reports lead with score and findings** - `analyse` and `summary` show totals first and name the subcommand.

## 0.3.0 - 2026-05-31

0.3.0 focuses on agent-friendly CI: scan only changed code, respect ignored paths everywhere, and fail on newly introduced debt instead of old baseline debt. It also removes noisy complexity/design checks and tightens the rules that support human review of AI-written code.

- **Changed-code scanning** - `analyse` filters to edited ranges or symbols; JSON reports how many older findings were suppressed.
- **Ignore handling is stricter** - `paths.ignore` covers explicit files and changed scans; `check-ignore` explains matches.
- **Baseline reporting is clearer** - Output separates new, unchanged, and resolved findings to show debt movement.
- **Count-based gates** - `failureConditions:` can gate total or severity counts; `--fail-on` remains supported.
- **New-findings gate** - `--fail-on-new` fails only on findings introduced by the current change. It requires a baseline, `--diff-vs`, or both.
- **Incremental cache** - `.gruff-cache/` reuses unchanged-file findings; project-wide analysis bypasses it.
- **Config presets** - `extends: gruff.recommended`, `gruff.starter`, or `gruff.strict` can replace most local config boilerplate.
- **BREAKING: Removed `complexity.npath`** - Delete its config and regenerate baselines; clearer complexity rules remain.
- **BREAKING: Removed synthetic `design.god-method` findings** - Component findings remain; remove stale baseline entries.
- **Fairer scoring** - Correlated size and complexity findings share one penalty but remain individually visible.
- **Boolean-name allowlist** - `naming.boolean-prefix` can now accept intentional names like `valid()` without forcing a public API rename.
- **Complexity rules recalibrated** - Halstead and maintainability are advisory; cognitive/nesting tighten; cyclomatic stays warning.
- **Fake-test rules are stricter** - Tests with no real assertion, no subject call, or tautological type assertions now fail at error level.
- **Config supports `advisory`** - Rule severity overrides now accept `advisory`.
- **More dead-code checks** - gruff can now flag unused private constants and unused project-owned internal classes, functions, and constants.
- **More secret checks** - Detects GCP service-account keys and HTTP(S) URL credentials while reporters redact raw values.
- **Mission documented** - README, docs, agent instructions, and ADR-017 now state the project goal: help humans verify AI-written code.
- **PHPDoc mixed rule relaxed** - Nullable JSON bags such as `array<string, mixed>|null` no longer trigger `phpdoc-mixed-overuse`.
- **Internal cleanup** - Large command and analysis classes were split up. CLI behaviour and output schemas are unchanged.
- **`docs.return-comment` changed meaning** - `@return` tags need a real description; regenerate affected baselines.
- **`docs.missing-param-tag` covers more methods** - Documented private/protected methods and functions now require tags.

## 0.2.0 - 2026-05-28

0.2.0 makes CI policy more explicit, adds rule triage help, and introduces several breaking config/schema changes.

- **BREAKING: `schemaVersion:` is required** - Add `schemaVersion: gruff-php.config.v0.1` to `.gruff-php.yaml`, or run `gruff-php init --force`.
- **BREAKING: `analyse --fail-on` defaults to advisory** - Use `--fail-on error` or set `minimumSeverity.analyse: error` to restore it.
- **BREAKING: JSON schemas moved to v2** - `summary` and `analyse` now emit v2 schemas and singular severity count keys. Update JSON consumers.
- **BREAKING: Removed `naming.parameter-type-name`** - Delete any config for this rule. Findings disappear automatically.
- **BREAKING: `waste.one-line-method` defaults tightened** - Most projects see fewer findings. Pin the old options only if you need the old behaviour.
- **Per-command severity config** - `minimumSeverity:` lets config set fail thresholds for `analyse`, `report`, and `dashboard`.
- **Visibility-only rules** - `excludeFromScore: true` keeps a rule visible in reports without affecting scores.
- **Rule triage help** - `list-rules <id>` shows options, escapes, and false-positive notes; text reports point to `summary`.
- **Stable finding identity** - JSON adds a line-shift-resistant `stableIdentity` field for external diff tooling.
- **Fewer mixed-type false positives** - Precise `array{...}` PHPDoc shapes with useful sibling fields no longer trip `phpdoc-mixed-overuse`.
- **Cleaner first run** - `init` now seeds common abbreviations, reports are easier to scan, and rule messages point at config escape hatches.
- **Bug fixes** - Fixed report/dashboard fail-threshold loading, abbreviation defaults, and `@param-out` / `@param-immutable` handling.
- **Regression tests** - Added coverage for fail-threshold parsing, precedence, report hints, and new accessors.

## 0.1.3 - 2026-05-24

Patch release for Composer installs.

- **Installed binary fixed** - `vendor/bin/gruff-php` now finds Composer's generated autoload path in consuming projects.
- **Packaging regression test** - Tests now cover installing and running `vendor/bin/gruff-php init` from a throwaway project.

## 0.1.2 - 2026-05-24

Harness and documentation maintenance for goat-flow 1.7.0.

- **Agent instructions updated** - Codex and Claude docs now use the packaged goat-flow audit CLI and list the real project quality surface.
- **Security references cleaned up** - Old goat-security stubs now redirect to the current identity/data and supply-chain/CICD references.
- **Architecture docs refreshed** - Code map and architecture docs now cover the current CLI and local workflow.
- **Hook fixtures fixed** - Dangerous-command hook tests now use the real fixture repository name.
- **Symfony YAML range widened** - Runtime support now matches the other Symfony components: `^6.4 || ^7.0 || ^8.0`.

## 0.1.1 - 2026-05-24

Onboarding-focused follow-up to 0.1.0.

- **`init` command added** - `gruff-php init` creates `.gruff-php.yaml`, preserves ignore patterns with `--force`, and supports `--project-root`.
- **Missing-config prompt** - TTY runs can offer to create config before scanning.
- **More test-quality rules enabled** - New default advisory rules catch common weak-test patterns.
- **Baseline guidance added** - `summary` now points users to baseline generation and no-baseline audit modes.
- **Dependency audit added** - Composer audit now runs in `composer check` and CI.
- **Docs expanded** - README and docs now cover rules, CI, config, output formats, dashboard, naming, and release process.

## 0.1.0 - 2026-05-23

First public release.

- **120 rules** - Covers size, complexity, maintainability, dead code, naming, docs, modernisation, security, sensitive data, tests, and design.
- **Five commands** - `analyse`, `summary`, `report`, `dashboard`, and `list-rules`.
- **Seven output formats** - `text`, `json`, `html`, `markdown`, `github`, `hotspot`, and `sarif`.
- **Strict YAML config** - `.gruff-php.yaml` supports baselines, branch review, mutation analysis, and dashboard settings.
- **PHP 8.3 and MIT** - Minimum runtime is PHP 8.3.0; license is MIT.

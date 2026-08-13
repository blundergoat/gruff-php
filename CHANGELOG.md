# Changelog

Notable user-facing changes to `gruff-php` are listed here.

## 0.5.2 - 2026-08-12

- **File and class lengths count substantive lines** - Blank and comment-only lines are free; thresholds stay unchanged and messages name the metric.
- **Regenerate size baselines** - Accepted size findings resurface; run `vendor/bin/gruff-php analyse --generate-baseline --fail-on none`.
- **SARIF size identities change** - Both size rules emit new `gruffStableIdentity` values because their messages changed.
- **Refresh class-length hook baselines** - `size.class-length` gets a new hook identity; `size.file-length` stays matched.
- **Procedural injection sinks are covered** - SQL and process rules resolve named arguments. SQL follows one unambiguous same-scope assignment; `proc_open()` keeps direct argument vectors safe unless they explicitly start a shell command mode.
- **Security baselines keep existing identities** - Existing messages stay stable; new procedural findings need review or baselining.
- **Public-property checks cover promotion** - Readonly classes stay quiet; public mutable promotions now report and may add baseline findings.
- **Prophecy expectations stay configured** - Native promises, predictions, and asserted `reveal()` values no longer look like bare mocks.
- **Prophecy baselines shed false positives** - Obsolete groups disappear; remaining messages and `gruffStableIdentity` values stay stable.
- **Empty scans are unscored** - `analyse` emits an `empty-analysis` diagnostic and omits the score when no PHP files are discovered.
- **Empty scans preserve exit policy** - The diagnostic is non-fatal, so zero-file runs still exit 0 without changing `--fail-on` behavior.
- **Empty-scan baselines are unchanged** - The diagnostic is not a finding, so it creates no `gruffStableIdentity` or baseline entry.
- **Promoted constructor docs stop duplicating** - Missing tags use `docs.missing-param-tag`, absent docblocks `docs.missing-public-phpdoc`.
- **Four rule options added** - Tune generic names, property line comments, dangerous functions, and intentional public-state classes.
- **Generated config explains its rules** - `init` writes each rule's description as its comment; size rules now name substantive-line counting.
- **Agent-hook parsers close bypasses** - Protected paths stay blocked through curl form headers, xargs optional flags, escaped filenames, mixed-case environment assignments, and multi-batch scans.
- **`summary` applies the secret allowlist** - Vetted `allowlists.secretPreviews` findings no longer inflate its counts, grade, or rule table.
- **Named arguments resolve at global sinks** - Security rules read `header(header: $x)` like `header($x)`; method and constructor sinks stay positional.
- **Named guards still count as guards** - `simplexml_load_string(data: $xml, options: LIBXML_NONET)` reads as protected rather than unguarded.
- **`sqlsrv_query` accepts both parameter spellings** - Microsoft documents `tsql`, the bundled stub says `sql`; either resolves the query slot.
- **Security baselines may gain named-argument rows** - Calls written with named arguments were previously unreported; review or baseline the new findings.

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

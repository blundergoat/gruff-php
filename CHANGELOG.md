# Changelog

Notable user-facing changes to `gruff-php` are listed here.

## 0.4.0 - 2026-06-11

0.4.0 retires the project rules whose whole-project analysis made per-edit feedback slow and whose verified false-positive rates made their findings untrustworthy on framework code. With no project rules left, the per-file result cache introduced in 0.3.0 now engages on every default run, and single-file scans no longer pay whole-project cost. Eight per-unit rules also gained precision fixes for mechanical misfires.

- **BEHAVIOUR CHANGE: `analyse --include-rule`/`--exclude-rule` now select rule execution** - The flags were display-only: an excluded rule still ran at full cost and its findings still counted toward `--fail-on`, `failureConditions`, scoring, and `--generate-baseline`. They now refine the run's rule selection exactly like `hook --include-rule`/`--exclude-rule`: an excluded rule does not execute at all, its findings neither display nor count toward the exit code, and `--include-rule` runs only the named rules. An unknown rule id passed to either flag is now a usage error (exit 2) on both `analyse` and `hook` instead of being silently ignored, so a typo cannot select an empty or wrong rule set. `report` forwards both flags to analyse, so it changes identically; `summary` and `dashboard` never exposed them. `--min-severity` and `--include-pillar`/`--exclude-pillar` remain display-only.
- **`analyse` resolves its enabled-rule set once per run** - The rule registry memoises the enabled-rule list per immutable config and snapshots rule definitions at construction, instead of re-filtering all 129 rules (rebuilding every definition) once per analysed file. Findings are byte-identical.
- **BREAKING: Removed `dead-code.unused-internal-class`** - Sampled false-positive rates of 95% (shopware) and 92% (mautic): XML dependency-injection wiring, migration directory-scan discovery, and PHP route arrays are invisible to its reference index, and it forced a 13-31s whole-project reparse per single-file scan.
- **BREAKING: Removed `dead-code.unused-internal-constant`** - Shares the same blind reference index and the same whole-project cost as the class rule, with the same per-edit latency.
- **BREAKING: Removed `dead-code.unused-internal-function`** - Same reference index, same blindness, same whole-project cost as the other internal-symbol rules.
- **BREAKING: Removed `design.single-implementor-interface`** - Sampled false-positive rates of 45% (shopware/mautic) to 100% (jetpack/woocommerce): no extends propagation, docblock types, or `::class` reference counting, so extension-point interfaces were systematically misread.
- **Configs naming removed rules keep working** - An unknown rule id under `rules:` now prints a one-line warning on stderr and the block is ignored, instead of failing the run. Existing init-generated configs that still carry the retired rule blocks run unchanged. `selection:` entries still reject unknown ids because they change which rules run.
- **Per-file result cache now engages by default and scales to 10k-file repos** - The cache was bypassed whenever a project rule was enabled, which default config always did. With the project rules retired, `.gruff-cache/` is populated by ordinary runs and warm whole-repo scans drop sharply (measured on this repo: ~4s to 0.09s). Three scale fixes keep large repos fast: the entry cap is raised from 4096 to 32768 (~320MB steady-state store worst case) so large repos get warm-cache reuse (the cap must cover the DISCOVERED file count - PHP plus text units; shopware discovers 17,543), eviction now runs once at the end of a run instead of globbing the cache directory on every write, and a run whose discovered file count exceeds the cap silently skips the cache for that run (entries would be evicted before any warm run could reuse them, so caching would be pure overhead). Corpus retest on 2026-06-10 (PHP 8.3.30, `scan-test-repos`): cold/no-cache whole-repo scans for jetpack/mautic/woocommerce/shopware were 38.46s/33.41s/48.04s/81.85s; clean cache-fill runs were 39.47s/34.04s/50.29s/84.39s; warm cache-hit runs were 2.07s/1.80s/2.18s/4.97s with byte-identical findings arrays versus cold.
- **Single-file `analyse`/`hook` is no longer O(project)** - Scanning one file no longer triggers a whole-project reparse (historical real-framework single-file user time: 13.7s/13.0s/20.8s/31.0s for jetpack/mautic/woocommerce/shopware; retested with `--no-cache` at 0.07-0.09s wall and 44-45MB RSS; this repo: 1.75s to 0.08s).
- **`test-quality.extends-production-class` recognises snake_case test bases** - `WC_Unit_Test_Case`-style parents no longer read as production classes, and `additionalTestBaseClasses` covers bases matching neither shape.
- **`naming.boolean-prefix` understands snake_case prefix boundaries** - `is_valid`-style names now satisfy the prefix check instead of being flagged.
- **`modernisation.forbidden-global-access` no longer flags superglobal writes** - Writes and `unset()` on `$_GET`/`$_POST`/etc. (request-simulation test setup) pass; reads still flag, matching the rule's documented semantics.
- **`naming.identifier-quality` loop escape hatch reaches closure params** - The sole parameter of a closure passed to `array_filter`/`array_map`-style iteration callables now honours `loopBodyThreshold` as documented.
- **`security.variable-include` recognises provably fixed constant and local paths** - `require_once ABSPATH . 'wp-admin/x.php'`-style ALL-CAPS constant concatenation and locals whose every same-scope assignment is a fixed path (`$dir = __DIR__ . '/inc/'; require $dir . 'z.php';`) no longer flag, including locals first read by non-mutating path guards such as `file_exists()` or `is_readable()`. Class constants, non-ALL-CAPS names, tainted or mixed assignments, by-reference/unknown-call mutation, and anything reachable from request data still flag. New options: `treatGlobalConstantsAsFixed` (default `true`) and `dynamicPathConstants` to re-flag specific constant names. Retested corpus counts: jetpack 178, mautic 25, woocommerce 156, shopware 21.
- **`sensitive-data.high-entropy-string` exempts identifier, key, and alphabet literals** - PHPCS sniff ids (`PHPCompatibility.FunctionUse.NewFunctions.ldap_exop_syncFound`), class names (`WPCOM_REST_API_V2_Endpoint_External_Media`), BEM class names, package slugs (`Automattic/i18n-check-webpack-plugin`), quoted object/array keys, PHPUnit `DataProvider` method-name references, and ordered parser/generator keyspaces (`ABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789`) no longer flag. A literal with no `+`/`=` that splits on `[/._-]` runs into two or more alphanumeric segments reads as an identifier when tokenized alphabetic words of three or more characters supply strictly more than half of all alphanumeric characters and no long non-word segment dominates. The character-weighted census is the load-bearing structure - short dictionary words cannot outvote a long random run - so prefixed keys (`config_prod_<random>`), slugs and identifiers with random tails, base64/hex tokens, npm `sha512-...` integrity hashes, and dot-joined JWT/JWE tokens keep flagging. Retested corpus counts: jetpack 72, mautic 34, woocommerce 2, shopware 86.
- **`sensitive-data.pii-test-fixture` honours its own remediation** - Emails on reserved special-use TLDs (`.local`, `.test`, `.invalid`, `.localhost`, `.example` - for example `get_customer_test@woo.local`) and addresses whose matched tokens or surrounding line carry a synthetic marker word (`test`, `fake`, `sample`, `demo`, `anytown` - for example `123 Test St` or `134 Main St, Anytown, USA`) no longer flag. Realistic emails, unmarked addresses, and phone numbers outside the 555-010x block still flag.
- **`security.sql-concatenation` understands prepare(), identifier interpolation, and non-SQL query()** - `$wpdb->prepare()` templates are inspected instead of blanket-flagged (a local interpolated into the template still flags), `{$wpdb->prefix}`-style property fetches on receivers in the new `safeInterpolationReceivers` option (default `['wpdb']`) no longer flag by themselves, and a word-bounded SQL keyword must appear in the literal fragments so `DOMXPath::query()` and other non-SQL receivers stay quiet.
- **Config-less runs no longer hang or write config on piped stdin** - In a project with no `.gruff-php.yaml`, `analyse`/`summary`/`report`/`dashboard` without `-n` used to block forever on the init offer when stdin was an open-but-silent pipe, and piped input whose first line started with "y" (for example a file list beginning `yarn.lock`) silently wrote `.gruff-php.yaml`. The offer now appears only when the process's real stdin is a terminal (an explicitly set input stream still counts as answerable) and the output is human-oriented: machine-readable formats — every `analyse --format` except `text`, `summary --format json`, and `report --format json` — always skip it silently. `report --format html` keeps the offer for terminal users because the prompt rides stderr and cannot corrupt the artifact, and `report` now passes `--no-interaction` to its child analyse process. A real terminal user with text output still gets the offer.

## 0.3.1 - 2026-06-09

0.3.1 adds the `gruff.hook.v1` agent-hook contract (`gruff-php hook --format json`) for editor and coding-agent integrations, plus one conservative test-quality rule, fixes Symfony YAML route and changed-region accounting edges in project-wide dead-code analysis, and moves the headline numbers to the top of text reports. No breaking changes; JSON schemas, config format, and baselines are unchanged.

- **Agent-hook contract output** - Added `gruff-php hook --format json` with the `gruff.hook.v1` contract for editor and coding-agent integrations. The new hook surface advertises itself through `hook --capabilities --format json`, emits normalized finding fields (`scope`, non-null `remediation`, threshold `metadata.measured/threshold/unit/direction`, and hook-stable `stableIdentity`), reports ignored paths under `ignored.paths`, surfaces config-schema failures in-band, and exits zero when analysis runs with findings. Hook `--baseline`, `--diff`, and `--since` use value-independent identities so pre-existing findings stay suppressed across line shifts and measured-value changes, while newly introduced findings still surface.
- **Hook-only changed-region fairness** - `hook --changed-ranges ... --changed-scope=symbol` now returns changed line/symbol findings but omits file/project-scope findings, including anchor-line residuals, unless they are new versus a supplied hook baseline or diff base. This keeps coding-agent feedback focused on attributable edits without changing existing `analyse`, `summary`, or CI JSON output.
- **Fairer changed-region symbol scope for aggregate findings** - `--changed-scope=symbol` now drops file/class aggregate findings such as `size.file-length`, `size.class-length`, and `docs.todo-density` when the changed hunk does not touch their reported anchor, while ordinary method/symbol findings still follow their enclosing changed declaration. Full scans still report the aggregate findings. Use the new `--changed-scope=file` mode when changed-file review workflows should keep file-level aggregates and class aggregate span hits.
- **New rule `test-quality.static-analysis-redundant-test`** - Advisory rule that flags unit tests whose main assertion only restates a statically visible declaration: `class_exists`, `interface_exists`, `trait_exists`, `enum_exists`, `method_exists`, or `property_exists` on a type declared in the same file. Each finding names the static fact the assertion restates and recommends asserting behaviour instead of deleting the test; it does not duplicate the existing `test-quality.tautological-type-assertion` hard gate. On by default at advisory, so upgrading projects may see new advisory findings - they are candidates, not gate failures.
- **Symfony YAML route controllers count as live references** - `dead-code.unused-internal-class` now recognises internal `FQCN::method` values under Symfony YAML `_controller` keys and the 4.1+ top-level `controller:` route shortcut, including block, inline, and quoted route defaults. Service-id and legacy non-FQCN controller strings are ignored, so projects with YAML routes no longer need to add those controllers to `entrypointSymbols` just to avoid this false positive.
- **Changed-region suppression counts are scoped to changed files** - `suppressedCount` now reconciles with the findings anchored to the changed/requested files after project-wide rules have used whole-project context. The count is also mirrored as `diff.suppressedCount` in JSON reports.
- **Text reports lead with score and findings** - `analyse` and `summary` text output now show `Composite:` and `Findings: N total · N error · N warning · N advisory` at the top, and the header names the subcommand (for example `gruff-php ... analyse`).

## 0.3.0 - 2026-05-31

0.3.0 focuses on agent-friendly CI: scan only changed code, respect ignored paths everywhere, and fail on newly introduced debt instead of old baseline debt. It also removes noisy complexity/design checks and tightens the rules that support human review of AI-written code.

- **Changed-code scanning** - `analyse` can now report only findings tied to edited ranges or symbols. JSON reports say how many older findings were suppressed.
- **Ignore handling is stricter** - `paths.ignore` now applies to explicit files, diff scans, and changed-region scans. `check-ignore` explains why a path is ignored.
- **Better baseline reporting** - Baseline output now separates findings into `new`, `unchanged`, and `resolved`, so teams can see debt movement instead of only muting old issues.
- **Count-based gates** - `failureConditions:` can fail a run by total finding count or by advisory/warning/error counts. Existing `--fail-on` behaviour still works.
- **New-findings gate** - `--fail-on-new` fails only on findings introduced by the current change. It requires a baseline, `--diff-vs`, or both.
- **Incremental cache** - Eligible runs reuse findings for unchanged files through `.gruff-cache/`. Runs that need whole-project analysis still bypass the cache.
- **Config presets** - `extends: gruff.recommended`, `gruff.starter`, or `gruff.strict` can replace most local config boilerplate.
- **BREAKING: Removed `complexity.npath`** - Delete any `rules.complexity.npath` config and regenerate baselines. The rule was noisy and overlapped with clearer complexity metrics.
- **BREAKING: Removed synthetic `design.god-method` findings** - Size and complexity findings still report normally, but gruff no longer adds a duplicate design finding. Remove stale baseline entries.
- **Fairer scoring** - Related size and complexity findings on the same method now count as one scoring penalty, while still appearing as separate findings.
- **Boolean-name allowlist** - `naming.boolean-prefix` can now accept intentional names like `valid()` without forcing a public API rename.
- **Complexity rules recalibrated** - Halstead volume and maintainability index are advisory; cognitive and nesting thresholds are tighter; cyclomatic complexity is warning-level.
- **Fake-test rules are stricter** - Tests with no real assertion, no subject call, or tautological type assertions now fail at error level.
- **Config supports `advisory`** - Rule severity overrides now accept `advisory`.
- **More dead-code checks** - gruff can now flag unused private constants and unused project-owned internal classes, functions, and constants.
- **More secret checks** - gruff now detects GCP service-account keys and credentials embedded in HTTP(S) URLs, with reporter tests to keep raw secrets redacted.
- **Mission documented** - README, docs, agent instructions, and ADR-017 now state the project goal: help humans verify AI-written code.
- **PHPDoc mixed rule relaxed** - Nullable JSON bags such as `array<string, mixed>|null` no longer trigger `phpdoc-mixed-overuse`.
- **Internal cleanup** - Large command and analysis classes were split up. CLI behaviour and output schemas are unchanged.
- **`docs.return-comment` changed meaning** - Same rule id, new behaviour: value-returning `@return` tags need a real description. Baselines may shift.
- **`docs.missing-param-tag` covers more methods** - Documented private/protected methods and functions now need `@param` tags. Baseline old gaps if needed.

## 0.2.0 - 2026-05-28

0.2.0 makes CI policy more explicit, adds rule triage help, and introduces several breaking config/schema changes.

- **BREAKING: `schemaVersion:` is required** - Add `schemaVersion: gruff-php.config.v0.1` to `.gruff-php.yaml`, or run `gruff-php init --force`.
- **BREAKING: `analyse --fail-on` default changed** - `analyse` now fails on advisory findings by default. Pass `--fail-on error` or set `minimumSeverity.analyse: error` to keep the old gate.
- **BREAKING: JSON schemas moved to v2** - `summary` and `analyse` now emit v2 schemas and singular severity count keys. Update JSON consumers.
- **BREAKING: Removed `naming.parameter-type-name`** - Delete any config for this rule. Findings disappear automatically.
- **BREAKING: `waste.one-line-method` defaults tightened** - Most projects see fewer findings. Pin the old options only if you need the old behaviour.
- **Per-command severity config** - `minimumSeverity:` lets config set fail thresholds for `analyse`, `report`, and `dashboard`.
- **Visibility-only rules** - `excludeFromScore: true` keeps a rule visible in reports without affecting scores.
- **Rule triage help** - `list-rules <ruleId>` now shows options, escape hatches, and false-positive notes. Large text reports point users toward `summary`.
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

- **120 rules** - Coverage spans size, complexity, maintainability, dead code, naming, docs, modernisation, security, sensitive data, test quality, and design.
- **Five commands** - `analyse`, `summary`, `report`, `dashboard`, and `list-rules`.
- **Seven output formats** - `text`, `json`, `html`, `markdown`, `github`, `hotspot`, and `sarif`.
- **Strict YAML config** - `.gruff-php.yaml` supports baselines, branch review, mutation analysis, and dashboard settings.
- **PHP 8.3 and MIT** - Minimum runtime is PHP 8.3.0; license is MIT.

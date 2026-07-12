# Changelog

Notable user-facing changes to `gruff-php` are listed here.

## Unreleased

- **Regex purpose comments follow their owning statement** - `docs.regex-comment` now accepts an own-line explanation directly above a multiline condition, assignment, return, or other nearest statement, and accepts a plain-language whitespace-fold contract only for a statically visible `preg_replace('/\s+/', ' ', ...)` transformation. Blank-line, previous-statement, trailing same-line, generic `safe`/`valid`, and unrelated replacement cases still report. A broad regex/pattern callable docblock now covers at most one configured call, so callables containing several operations may gain findings until each operation has local purpose documentation.
- **Named callback boundaries stay named** - `waste.one-line-method` now preserves an exact same-class comparator, serializer, or other method referenced with PHP first-class callable syntax or a vetted two-element callable array using `$this`, `self::class`, `static::class`, `__CLASS__`, or the fully resolved declaring class. Ordinary one-shot wrappers and unproven dynamic, foreign, computed, string, or child-name-to-parent-declaration callback shapes still report; use `rules.waste.one-line-method.options.allowedSymbols` when runtime/framework wiring makes one of those conservative shapes intentional.
- **Boolean state names use conservative word boundaries** - `naming.boolean-prefix` now accepts `requested` and `present` only as whole-token suffixes on multi-token names, plus subject-first `requires` propositions that have context on both sides. Typed properties and parameters also accept the whole names `resolved`, `limited`, and `printable`; single-token callables, vague nouns, incomplete propositions, and suffix fragments still report. Projects can replace the shipped suffix and proposition vocabularies through `stateSuffixAllowlist` and `propositionVerbAllowlist`, while `acceptedBooleanNames` remains the exact non-breaking hatch.
- **Pattern-family constant comments stay bounded** - `docs.missing-constant-phpdoc` now recognises explicit `patterns` and `regexes` comments as documentation for at most five immediately contiguous constant names, counting every name in multi-name declarations. The sixth and later names, visibility changes, blank/non-constant/PHPDoc/local-comment boundaries, and unrelated or unexplained constants still report. Existing group words such as `keys` and `values` remain uncapped, and a mixed comment follows that shipped behavior.

## 0.5.0 - 2026-07-03

0.5.0 makes gruff's identities line-stable — baselines, branch review, and SARIF all stop churning when unrelated edits shift line numbers — sharpens eight rules against false positives and evasion gaps, brings `report` up to `analyse`'s workflow surface, and makes release version bumps drift-proof.

- **BREAKING: Baselines move to `gruff.baseline.v2` grouped counts** - Baseline files now store one `{file, ruleId, message, count}` row per accepted finding group instead of per-finding fingerprints, and matching is count arithmetic per group, so inserting or reformatting code above accepted debt no longer resurfaces it as new (ADR-029). Migration: regenerate committed baselines once with `gruff-php analyse --generate-baseline` — legacy `gruff.baseline.v1` files fail closed with that instruction (exit code 2) rather than parsing silently. Because the message is part of the match key, rule-message rewording in this release also invalidates affected groups; regenerate after upgrading. Known blind spot: replacing one accepted instance with a different violation of the same file, rule, and message stays within the group budget and reports as unchanged. JSON finding `fingerprint`/`stableIdentity` values and SARIF fingerprints are unaffected; `--fail-on-new` now derives "new" from group-count overflow.
- **BREAKING: `--profile security` rejects out-of-profile `--include-rule` ids** - Previously `--profile security --include-rule docs.missing-public-phpdoc` ran the documentation rule and emitted its error while the composite stayed a security-only 100, so the grade did not match the emitted findings. Such combinations now exit `2` with a usage error naming the rule's pillar and both remedies (ADR-030). Security/sensitive-data includes, `--exclude-rule` narrowing, and everything under `--profile default` behave exactly as before.
- **SARIF results now carry a line-stable partial fingerprint** - Each SARIF result's `partialFingerprints` adds `gruffStableIdentity` (the line-insensitive `stableIdentity` already present in JSON findings) alongside the existing `gruffFingerprint`, which is unchanged and stays byte-compatible. SARIF consumers such as GitHub Code Scanning can now keep an alert open across unrelated line drift instead of closing and reopening it.
- **Branch review no longer flips line-shifted symbol-less findings** - `--diff-vs` comparison keys symbol-less findings on `(file, ruleId, message)` — the same identity baselines use — instead of falling back to `line:endLine:column`. A symbol-less finding that only moved lines now compares as unchanged rather than one removed plus one introduced; duplicate occurrences still count individually.
- **Machine-readable output survives invalid UTF-8 bytes** - JSON, hotspot, SARIF, summary JSON, and hook JSON now encode with `JSON_INVALID_UTF8_SUBSTITUTE`, replacing invalid source-derived bytes (for example a method name with raw high bytes) with `�` instead of crashing the run or, for SARIF, degrading to an encode-error stub. Finding `fingerprint`/`stableIdentity` hashing, baseline writes, and trend-history writes are hardened the same way; hashes of valid-UTF-8 findings are byte-identical to before.
- **Security taint tracking follows `.=` compound assignment** - `$value = 'X-Trace: '; $value .= $_GET['trace']; header($value);` and similar laundering through concat assignment now reaches every shared-helper security sink: header injection, reflected XSS, variable include, process command construction, request-controlled URL, and the path/XML/archive checks. A clean reassignment still clears taint, and a clean `.=` onto a clean value stays silent. Reference assignment (`=&`) and arithmetic assignment operators remain intentionally untracked: an alias's later writes flow both ways, which last-write-wins tracking would classify backwards.
- **`security.unsafe-xml-loading` requires an XML-capable receiver** - Method and static calls named `open`, `load`, `loadXML`, or `xml` are now flagged only when the receiver carries XML-parser evidence: inline `new`, an allowlisted static class (`DOMDocument`, `SimpleXMLElement`, `XMLReader`), or a variable whose same-scope writes leave it possibly bound to one. Writes in a branch the sink does not share can add XML evidence but never erase it, so a conditional rebind cannot hide a real parser. `ZipArchive::open($_GET[...])`, ORM `load()`, and builder `xml()` calls no longer produce XML warnings; global `simplexml_load_*` detection and `LIBXML_NONET` handling are unchanged.
- **`security.unsafe-archive-extraction` catches uploaded archives with fixed destinations** - The rule now connects `$zip->open($_FILES['archive']['tmp_name'])` (or `new PharData($_FILES[...])`) to a later `$zip->extractTo('/fixed/path')` within the same scope and reports a distinct `Archive extraction of a request-controlled archive source detected.` finding (metadata `taint: archive-source`). An unconditional clean re-open or reassignment clears the source taint, but a clean re-open in a branch the extraction does not share cannot — the runtime could skip it. Destination and entry-list checks are unchanged. The rule flags a request-controlled source, not proven zip-slip — entry names are still not inspected.
- **Opaque dotted tokens no longer evade secret detection** - `sensitive-data.high-entropy-string` previously skipped every literal containing exactly two dots (intended to delegate JWTs). It now skips only genuinely JWT-shaped literals (the three-segment `eyJ` shape, shared with `sensitive-data.jwt-token`), so a high-entropy `prefix.random.signature` session or signing token is reported. Real JWTs still report once, under the JWT rule only; dotted routes, versions, domains, and paths stay silent — the path-literal exemption also recognises `.sh` script paths now that the two-dot skip no longer masks them.
- **`complexity.cognitive` counts `match` expressions** - A multi-arm `match` previously scored zero cognitive complexity, so switch-to-match rewrites could bypass an error-severity gate. Match now mirrors cognitive `switch` scoring: one increment for the construct (never per arm) plus recursive scoring of arm conditions and bodies one nesting level deeper — a small two-arm match scores 1 and cannot trip default thresholds. Cyclomatic complexity is unchanged and intentionally still charges each arm. Methods containing `match` may gain or grow cognitive findings; because messages embed the computed score, affected baseline groups need a regenerate.
- **`dead-code.unused-private-method` respects dynamic dispatch** - A computed same-class dynamic call (`$this->{$method}()`, `self::{$expr}()`, `static::{$expr}()`, or `[$this, $method]` with a computed method slot) now suppresses unused-private-method findings for that class, trait, or enum instead of telling a reviewer to delete live handlers. String-literal dynamic names like `$this->{'handle'}()` resolve precisely (siblings still report), and dynamic calls on other objects never suppress anything.
- **`docs.missing-throws-tag` documents the method's own throws only** - Throws inside nested closures, arrow functions, anonymous classes, nested functions, and immediately invoked closures no longer require `@throws` on the containing method — they belong to their own scope's contract, just like calling any throwing function. Direct throws in methods and free functions report exactly as before.
- **`naming.negative-boolean` covers snake_case names** - Typed bool properties and parameters like `$no_cache` and `$not_ready` now report the same negative-flag guidance camelCase names always got; `naming.boolean-prefix` and `naming.negative-boolean` share one prefix/boundary predicate so a name can never fall between them again, and each identifier reports at most once. `cliMirrorAllowlist` exemptions work identically for snake_case mirrors of CLI flags; words that merely start with "no"/"non" (for example `$normalised_output`) never match.
- **Trend deltas compare like-for-like scopes only** - `--history-file` runs still append every entry, but `trend.delta` now selects the latest history entry with the same score scope: full-project runs compare against full-project history, and `--diff`/`--since`/`--changed-ranges` runs compare against earlier diff-scoped entries. A diff run against full-project-only history reports `previousScore`/`delta` as null instead of a meaningless cross-scope delta, and the trend block gains a `scope` field naming its series. Existing history files, including entries predating the scope field (treated as full-project), stay readable.
- **`report` supports the analyse workflows it used to reject** - `report` now accepts and forwards `--profile`, `--since`, `--changed-ranges`, `--changed-scope`, `--fail-on-new`, `--no-cache`, `--baseline-include-absent`, `--infection-run`/`--infection-bin`/`--infection-config`/`--infection-test-framework-options`, and `--print-runtime`/`--runtime-mode`, so saved HTML/JSON reports come from the same changed-scope, new-findings, and cache workflows as `analyse`. With `--fail-on-new` the artifact is still written and the exit code reflects the gate. `--generate-baseline` and `--file` intentionally stay analyse-only (pass report paths positionally).
- **Version bumps update every stamp or fail loudly** - `scripts/bump-version.sh` now also rewrites the README "Current source" row and both `docs/gruff-cli-summary.md` stamps, and the preflight version check verifies all of them against `Application::VERSION`, naming the exact stale file on drift. CLI golden fixtures normalise their version stamps to `Application::VERSION` at compare time and version-sensitive test assertions derive from the constant, so a bump can no longer leave hidden test-breaking literals. The workflow is documented in `docs/releasing.md`.

## 0.4.1 - 2026-06-13

0.4.1 focuses on rule-rubric precision: fewer false positives and fewer over-severe findings without disabling the rules that catch real maintainability problems.

- **BREAKING: Removed `modernisation.enum-candidate`** - Classes that only hold scalar constants are no longer flagged to convert to enums. That's a deliberate design choice, not a default gruff rule, because such constants often cross PHP, Twig/TypeScript, JSON, telemetry, and agent/runtime boundaries.
- **Tighter rubrics across docs, naming, size, complexity, modernisation, and dead-code** - Fewer false positives and over-severe findings. Now ignored: well-commented constants, intentional one-liners, more boolean-verb names (`includes*`/`excludes*`/`enables*`/`disables*`), immutable data carriers, flat guard-clause validators, clear positional calls, and callable-referenced private methods. Still flagged: useless comments, undocumented constants, bad wrappers, dependency-heavy services, nested complexity, ambiguous positional calls, and real dead code.
- **Constant PHPDoc strictness is configurable** - By default, `docs.missing-constant-phpdoc` accepts a meaningful `//`, `#`, or block comment (including short grouped ones). Projects that treat constants as public API can require full PHPDoc - everywhere with `requirePhpdocForApiConstants`, or per path with `apiPathPatterns`.
- **`report` rejects unknown rule filters as a usage error** - `report --include-rule`/`--exclude-rule` now check ids up front, so a typo fails fast (exit code 2) before any prompt, config write, or `analyse` run - just like `analyse` already does.
- **Internal namespace consolidation** - `src/` drops from 19 top-level directories to six (`Cli`, `Engine`, `Rules`, `Results`, `Output`, `Support`). The Composer root stays `GruffPhp\ => src/`; rule IDs, command names, config keys, JSON schemas, and baselines are unchanged, so CLI, hook, and CI output are identical. Only code that imported gruff-php's internals directly needs updating (e.g. `GruffPhp\Rule\` → `GruffPhp\Rules\`).

## 0.4.0 - 2026-06-11

0.4.0 retires the project rules whose whole-project analysis made per-edit feedback slow and whose verified false-positive rates made their findings untrustworthy on framework code. With no project rules left, the per-file result cache introduced in 0.3.0 now engages on every default run, and single-file scans no longer pay whole-project cost. Eight per-unit rules also gained precision fixes for mechanical misfires.

- **BEHAVIOUR CHANGE: `analyse --include-rule`/`--exclude-rule` now select rule execution** - Excluded rules no longer run or count toward `--fail-on`, `failureConditions`, scoring, or baselines; `--include-rule` runs only the named rules; unknown rule ids are now usage errors.
- **BREAKING: Removed `dead-code.unused-internal-class`, `dead-code.unused-internal-constant`, and `dead-code.unused-internal-function`** - False-positive rates up to 95% on framework code, and each forced a 13-31s whole-project reparse per single-file scan.
- **BREAKING: Removed `design.single-implementor-interface`** - 45-100% false-positive rates: extension-point interfaces read as single-implementor.
- **Configs naming removed rules keep working** - Unknown rule ids under `rules:` warn on stderr and are ignored; `selection:` entries still reject them.
- **Per-file result cache now engages by default** - Warm whole-repo scans on the framework test corpus dropped from 33-82s to 1.8-5.0s with byte-identical findings; the entry cap rose from 4096 to 32768.
- **Single-file `analyse`/`hook` is no longer O(project)** - One-file scans dropped from 13-31s to under 0.1s on real framework repos.
- **Naming, test-quality, and superglobal rules misfire less** - `test-quality.extends-production-class` recognises snake_case test bases, `naming.boolean-prefix` accepts `is_valid`-style names, `naming.identifier-quality`'s loop escape hatch reaches closure params, and `modernisation.forbidden-global-access` no longer flags superglobal writes.
- **Security rules exempt provably safe code** - `security.variable-include` accepts provably fixed include paths (new `treatGlobalConstantsAsFixed`/`dynamicPathConstants` options); `security.sql-concatenation` understands `$wpdb->prepare()`, safe identifier interpolation, and non-SQL `query()`.
- **Sensitive-data rules skip identifiers and synthetic fixtures** - `sensitive-data.high-entropy-string` exempts identifier/key/alphabet literals (real secrets and tokens keep flagging); `sensitive-data.pii-test-fixture` accepts reserved-TLD emails and marked synthetic addresses.
- **Config-less runs no longer hang or write config on piped stdin** - The init offer now appears only for terminal users with human-oriented output, so piped stdin cannot block a run or silently create `.gruff-php.yaml`.

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

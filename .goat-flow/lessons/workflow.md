---
category: workflow
last_reviewed: 2026-05-27
---

# Workflow Lessons

## Lesson: Universal defaults that ship via `init` must also flow through `AnalysisConfig` so existing projects benefit

**Created:** 2026-05-27

**What happened:** `InitCommand::DEFAULT_ACCEPTED_ABBREVIATIONS` was added in an earlier WIP commit so freshly-scaffolded `.gruff-php.yaml` files would seed the 16 universal abbreviations (`age, app, db, fs, id, io, key, log, max, min, now, raw, rx, tx, ui, url`) and avoid flooding `naming.abbreviation-allowlist` on first scan. But the runtime default — what `AnalysisConfig::fromRegistry()` returned when no user value was provided — was still `[]`. Existing projects with a `.gruff-php.yaml` that pre-dated the `init` change (or projects with no config at all) saw the rule fire on the same 16 abbreviations the init scaffold was specifically trying to silence. The "default" was effectively bifurcated: scaffold default vs runtime default.

**Root cause:** Treating "scaffolded into init" as equivalent to "shipped as a default". Scaffolded values land in the user's YAML once at write-time; runtime defaults govern every `AnalysisConfig` constructed at scan-time, including when no YAML is read (e.g., `--no-config`, missing file, programmatic construction in tests). Until the seed value lives in the runtime constructor, the "fresh init" experience and the "existing project" experience diverge silently.

**Prevention:** When adding a new universal default that ships via `gruff-php init`, the value must live on `AnalysisConfig` as a `public const` (or a value object equivalent) and `AnalysisConfig::fromRegistry()` must populate the corresponding accessor. `InitCommand`'s scaffold constant should reference the `AnalysisConfig` constant directly (`AnalysisConfig::DEFAULT_ACCEPTED_ABBREVIATIONS`) so the two values cannot drift. Same shape for any future allowlist, ignore-path default, or threshold floor: scaffold + runtime must share one declaration. The Hard Rules section of `CLAUDE.md` already says "If a file exists, modify it in place; do not create backup or `_new` variants" — the same single-source-of-truth principle applies to defaults: never declare the same default in two places.

## Lesson: When the dogfood config has tuned a rule, those tunings are the real defaults

**Created:** 2026-05-27

**What happened:** `waste.one-line-method` shipped with `minInFileCallers: 0` and `namedAlternativeFactoryExempt: false` as `defaultOptions`. The project's own `.gruff-php.yaml` overrode both to `minInFileCallers: 2` and `namedAlternativeFactoryExempt: true` because the unmodified defaults produced too much noise on this codebase. External adopters scanning their own code hit the same noise without the benefit of the self-tuned overrides — the "default" was effectively unusable. Lifting the dogfood values into the rule's `defaultOptions` removed three findings from the regression snapshot fixture (`AlternativeFactoryFixture::ready`, `AlternativeFactoryFixture::failed`, `OneLineMethodFixture::sharedHelper`) without disabling any real signal.

**Root cause:** Treating ship defaults and dogfood tuning as separate dimensions. The reasoning was: "ship the rule on the strict side, let projects opt into the lenient knobs." In practice, the only project that calibrated the rule chose strict because the lenient ship default was noisy on real code. The signal is: when the dogfood project has accumulated overrides for a rule, those overrides ARE the calibrated defaults; the ship defaults are an uncalibrated prior nobody has validated.

**Prevention:** Before shipping a rule's defaults, check whether the dogfood `.gruff-php.yaml` already tunes them. If so, the tuned values are the candidate ship defaults; the rationale to ship something different needs concrete evidence (a different project that benefits from the looser values). The project config can stay redundant (explicit pin) or be removed (relies on defaults); pick the redundant-pin shape until the new defaults have ridden at least one release without complaint, then revisit. Applies to any rule with knobs: thresholds, minimum-counts, exemption flags. Same lockstep applies as for "scaffolded init values must flow through `AnalysisConfig`" — one canonical declaration, multiple consumers, never two source-of-truth copies.

## Lesson: Keyword-based exemptions need fixture audits because existing positive-case fixtures often use the trigger words

**Created:** 2026-05-27

**What happened:** Added a docblock-keyword exemption to `docs.regex-comment` per M02 spec: when the enclosing function-like's docblock contains any of `regex`, `pattern`, `preg_`, `match`, or the call's own function name, skip the per-call comment requirement. The change compiled and unit-tested cleanly. Running the full suite, `testRegexCommentRequiresImmediatePurposeComment` failed with 0 findings instead of 2. Root cause: the existing fixture `tests/Fixtures/Docs/regex-comment.php` had method-level docblocks like "Check an undocumented regex call." and "Check a regex call separated from its context." — every test method's docblock contained the literal "regex". The new exemption swept ALL three methods, including the two that the test asserted should fire.

**Evidence:** `tests/Fixtures/Docs/regex-comment.php` (pre-fix) — three methods, all with docblocks containing "regex" in their human-readable summaries. PHPUnit failure diff showed `actual: []` against `expected: ['isSeparatedRegexMatch()', 'isUndocumentedRegexMatch()']`. The exemption was working exactly as specified; the fixture's authoring assumed no docblock-keyword check would ever exist.

**Root cause:** When the rule's behaviour expands to peek at the function-like's docblock, the docblock content of the existing fixture transforms from "test setup metadata" (irrelevant to the rule) into "test signal" (drives the rule's branch). Fixture authoring for the original rule didn't and couldn't anticipate the keyword set. The mismatch was invisible to type checks and to the per-rule unit test — only the corpus-level test surfaced it.

**Prevention:** Before adding a keyword-based exemption (docblock contains X, comment matches Y, method name matches Z), run the rule against every existing fixture that asserts the rule FIRES. Audit those fixtures' docblocks/comments/names for the proposed trigger words. Either:
- Reword the existing fixture's docblock to NOT contain the trigger word (preferred when the docblock is incidental setup — e.g., rewrite "Check an undocumented regex call." as "Validate the candidate against the fixture identifier shape." for `docs.regex-comment`).
- Or split the fixture: keep one method per intended-firing case with neutral docblocks, add a separate exemption-validation method with a triggering docblock.

The corpus-level regression test (`tests/Rule/RuleRegressionSnapshotTest.php` in this repo) catches the worst case — total rule extinction — but the per-rule fixture is the right place for the explicit before/after demonstration. Same audit applies to any rule that adds a context-aware exemption: PHPStan `@var` scaffold in `waste.redundant-variable`, function-doc keywords in `docs.regex-comment`, and any future exemption based on AST or docblock context.

## Lesson: Validator throws need an actionable hint, and the CLI catch must render it

**Created:** 2026-05-27

**What happened:** `ConfigLoader` throws `ConfigException` (extends `RuntimeException`) at every config-validation boundary — schemaVersion missing/mismatched, unknown rule id, malformed `minimumSeverity`, etc. The exception carries only a message; it has no hint field. CLI surfaces consume it inconsistently: `SummaryCommand.php:239` prints `<error>[CONFIG-ERROR] %s</error>` and returns null; `AnalyseCommandSetupBuilder.php:235` converts to a structured `usageReport` with `type: 'config-error'`; `ReportCommand.php:403` and `DashboardStateFactory.php:185` swallow silently (`catch (ConfigException) { return null; }`). None of the surfaces add an actionable next step. An operator who hits a `schemaVersion` mismatch from `summary` reads "Config must include schemaVersion: ..." and has to consult docs to learn that `gruff-php init --force` is the documented fix.

Cross-port: gruff-ts hit the exact same trap with worse symptoms (raw Node stack trace), captured in `gruff-ts/.goat-flow/lessons/workflow.md` "Lesson: user-facing CLI commands must catch known error classes and print graceful messages" (2026-05-27). The fix there was a typed `ConfigLoadError` with a `suggestion` field plus a `runWithConfigErrorHandling` wrapper. gruff-php has the catch boundary already; it's missing the hint payload and the consistent rendering.

**Root cause:** Treating "throw with a clear message" as the validator's whole job. The message says what broke; the hint says how to fix it; the catch's job is to render both. Skipping the hint puts the remediation work on the user and lets the four CLI commands diverge on what "graceful" means.

**Prevention:** When adding or auditing a validator that can throw at a user-input boundary:

- The error class must carry a hint field, not only a message. For `ConfigException`, extend the constructor with `string $hint = ''` (default empty preserves every existing throw site).
- Every throw site supplies a context-appropriate hint. Patterns: `"Run \`gruff-php init --force\` to regenerate .gruff-php.yaml from current defaults (preserves your paths.ignore and minimumSeverity entries)."` for regenerable issues; `"Edit .gruff-php.yaml to a supported value, or run \`gruff-php init --force\` to regenerate."` for value validation failures.
- Every Console command (`analyse`, `summary`, `report`, `dashboard`) catches `ConfigException` and renders message + hint with the same formatting. Inconsistency across commands (one prints, one swallows, one wraps) is itself the bug.
- See `.goat-flow/patterns/error-handling.md` "Pattern: Carry an actionable hint on every user-input exception" for the canonical recipe and the cross-port references.
- Do NOT catch `RuntimeException`/`LogicException` broadly — those are maintainer bugs and should keep their stack trace.


## Lesson: When introducing a required config field, auto-inject in the test helper rather than patching every fixture

**Created:** 2026-05-26

**Incident:** M11 introduced `schemaVersion: gruff-php.config.v0.1` as a required top-level field in `.gruff-php.yaml`, with `ConfigLoader::assertSchemaVersion` hard-erroring on missing/wrong values. The change was small (~10 lines of validator logic) but the blast radius surprised: 19 fixture configs in `tests/Fixtures/Config/`, the project's own `.gruff-php.yaml`, three inline `file_put_contents($path, "rules: {}\n")` calls in dashboard scan tests, four direct `file_put_contents` calls in `ConfigLoaderTest`, and every entry in `ConfigLoaderTest::invalidInlineConfigProvider` all loaded configs that now lacked `schemaVersion`. First test run after the validator landed: 24 errors + 51 failures, with most error messages identical (the migration hint) — a clear signal the schemaVersion guard was firing before any test could reach its actual assertion.

The instinct was to patch each fixture and each inline write individually. Quick math: 19 + 1 + 3 + 4 = 27 file-level changes plus 11 data-provider entry rewrites = ~38 separate edits. Many would be near-duplicates, and the inline JSON in `invalidInlineConfigProvider` would either need every test case to grow a `"schemaVersion":"gruff-php.config.v0.1"` prefix or get bypassed via a parallel data path.

Better approach taken: extend `ConfigLoaderTestCase::writeTempConfig` with an auto-inject helper that prepends `schemaVersion` whenever the contents don't already contain the string. `bash`/`sed` bulk-prepended the 19 fixture files in one loop; three `file_put_contents` callsites got two `Edit` calls (one used `replace_all` because two writes were identical); four direct-write tests in `ConfigLoaderTest` got four `Edit` calls. The data provider needed zero changes — auto-inject handled every case. Total: roughly 8 file-level changes instead of 38.

The auto-inject also kept the migration reversible: tests that intentionally test the missing-schemaVersion error path pass `shouldInjectSchemaVersion: false` to bypass the helper, so the same `writeTempConfig` powers both the "schemaVersion is auto-included for legacy tests" path and the "schemaVersion is deliberately absent so we can test the error" path.

**Do differently:** When a required schema field lands behind a hard-error validator and dozens of test fixtures need to acquire it:

- Identify the single test helper that constructs config files (in gruff-php this is `ConfigLoaderTestCase::writeTempConfig`; gruff-go's equivalent is the per-test inline `file_put_contents` pattern, which would benefit from a similar helper).
- Add an opt-out parameter (e.g. `bool $shouldInjectSchemaVersion = true`) so the auto-inject is the default but tests that exercise the missing-field error path can disable it.
- Bulk-prepend the field to long-tail YAML fixtures via `sed -i '1i ...'`. Verify with `head -2` on a sample file before declaring the migration complete.
- For inline JSON in data providers, the auto-inject helper detects the leading `{`, `json_decode`s, prepends the field, and `json_encode`s the result. No data-provider entry changes needed.

The fixture-per-field migration cost is roughly linear in the number of fixtures, but the helper-level auto-inject is roughly constant. The opt-out parameter is the seam that makes both paths testable.

## Lesson: Never replace hand-maintained project config with generated defaults

**Created:** 2026-05-24

**What happened:** The project `.gruff-php.yaml` was regenerated from `gruff-php init` defaults during rule-default work. That flattened repo policy to generic defaults, including replacing the maintained `paths.ignore` list with `ignore: []`. The removed policy covered `.agents/**`, `.antigravitycli/**`, `.claude/**`, `.codex/**`, `.github/**`, `.goat-flow/**`, `history.json`, `infection-report.json`, `src/Vendor/**`, and `tests/Fixtures/**`. The user caught the regression after later threshold work, and the ignore list had to be restored manually.

**Evidence:** `.gruff-php.yaml` (search: "Generated by `gruff-php init`") shows the generated-config header; `git show 77c34b5 -- .gruff-php.yaml` shows `paths.ignore` changing from the maintained list to `ignore: []`; `.gruff-php.yaml` (search: `.agents/**`) now shows the restored ignore entry.

**Root cause:** Treating generated init output as an acceptable replacement for an existing committed project config. The agent focused on rule defaults and test snapshots, but failed to review the full config diff for unrelated policy loss. A generated header does not mean the current committed file is disposable; once edited by the project, it is a policy artifact.

**Prevention:** Before editing `.gruff-php.yaml` or any project config, read the existing file and preserve unrelated policy exactly. After every config edit, run `git diff -- .gruff-php.yaml` and verify the diff only contains the intended keys. If a small rule-default change rewrites `paths`, `allowlists`, `selection`, comments, or unrelated thresholds, stop immediately and restore those sections from the pre-change version before proceeding. Never use `gruff-php init` output as a wholesale replacement for the repo's committed config unless the user explicitly asks to regenerate the whole file.

## Lesson: Fix generators when generated config is wrong

**Created:** 2026-05-24

**What happened:** After the project ignore list was restored in `.gruff-php.yaml`, the underlying `gruff-php init` generator still emitted a config that could recreate the same loss. A generated artifact fix was not enough because `init --force` could overwrite the existing file again.

**Evidence:** `src/Command/InitCommand.php` (search: `DEFAULT_IGNORED_PATHS`) now defines the default ignored paths for new config files; `src/Command/InitCommand.php` (search: `existingIgnoredPaths`) preserves an existing `paths.ignore` list during forced regeneration; `tests/Console/InitCliTest.php` (search: `testInitForcePreservesExistingPathIgnores`) locks the regression test.

**Root cause:** Stopping at the damaged generated file instead of tracing the behavior back to the code path that produced it. That left the same destructive behavior available through the CLI.

**Prevention:** When a generated or scaffolded file has lost important policy, always identify the generator before closing the task. Fix the source generator and add a test that exercises the real CLI path. For `gruff-php init`, both cases must be covered: a new config gets the default ignore list, and `--force` preserves an existing `paths.ignore` list.

## Lesson: Never run `git commit` or `git push`

**Created:** 2026-05-10

**What happened:** After the user authorised "verify and commit as 2 focused commits" via an in-conversation question, the agent ran `git commit -F <file>`. The harness denied the call. The agent then asked clarifying questions and re-attempted the same call, which was denied again. The user committed the staged changes themselves and made it explicit that coding agents must never run `git commit` (and equally never `git push`), regardless of any in-conversation authorisation, AskUserQuestion answer, or prior approval in any other session.

**Root cause:** Treating conversational authorisation ("commit it as two focused commits") as authority to invoke `git commit` directly. Conversational authorisation lets the agent prepare a commit (stage files, draft message, run checks); it does not grant the agent permission to call the commit tool itself. Harness-level deny on `git commit` / `git push` is the durable rule, and the agent missed that signal twice in a row.

**Prevention:**
- Never invoke `git commit` or `git push` via Bash. Treat both as user-only operations forever, in this repo and in every other repo, for every session. There is no scenario in which the agent should run these.
- When changes are ready to commit: stage with `git add <files>`, run the agreed checks (`composer check` / `composer test` / etc.), and write the commit message to `.goat-flow/scratchpad/commit.md`. Then tell the user the message is staged and stop. Do not retry, do not propose a workaround, do not suggest the user use `! git commit ...` — they will commit themselves whichever way they prefer.
- If the harness denies `git commit` or `git push` once, treat it as a permanent signal for the rest of the session and never attempt either again. Do not interpret silence or further user instructions as a re-authorisation.
- If you want a permission-layer enforcement, suggest `Bash(git commit:*)` and `Bash(git push:*)` entries under `permissions.deny` in `.claude/settings.json` — but do not edit that file without explicit user instruction.

## Lesson: Tick the per-task checkboxes when flipping a plan to complete

**Created:** 2026-05-10

**What happened:** Three milestone plans (`.goat-flow/tasks/0.1/M19-performance-and-rule-quick-wins.md`, `M20-phpstan-style-baseline-workflow.md`, `M21-sensitive-data-rename.md`) were marked `Status: complete` with evidence blocks at the top, but every `- [ ]` checkbox inside their `## Assumptions`, `## Tasks`, and `## Testing Gate` sections was left unticked. The user had to catch the inconsistency. After the prompt, `sed -i 's|^- \[ \]|- [x]|g'` ticked 21 + 21 + 25 boxes across the three files.

**Root cause:** Treating the top-of-file `Status:` line as the single source of truth for milestone completion. The status line is a summary; the per-task checkboxes are the audit trail. Skipping them leaves a future reader unable to tell which individual tasks were done, deferred, or silently dropped — and it falsely signals that the plan was never executed even when the work shipped.

**Prevention:** Whenever a plan's `Status:` field flips to `complete` (or any task's status changes), tick every `- [ ]` line in `## Assumptions`, `## Tasks`, and `## Testing Gate` that the work actually covered before claiming the milestone done. If a checkbox cannot be ticked, leave it unchecked and add a one-line note explaining why — `Status: complete` with mixed checkboxes is still valid, but only when the unticked items are deliberate. Use `sed -i 's|^- \[ \]|- [x]|g' <plan>` for whole-plan completion or edit individual lines for partial progress. Verify with `grep -c '^- \[x\]' <plan>` before claiming done.

## Lesson: Reconcile shipped work back into task checkboxes immediately

**Created:** 2026-05-20

**What happened:** Several v0.1 milestone files (`.goat-flow/tasks/0.1/M54-pest-expectation-allowlist-expansion.md`, `M56-size-rubric-metric-semantics.md`, `M57-statement-dispatch-visitor-consolidation.md`, and `M58-parameter-count-exemption-ceiling.md`) had already shipped in live source/tests, but their task files still said `Status: not-started` and every checklist item remained unchecked. The user had to ask whether the plans were still worth doing and then explicitly ask for completed boxes to be ticked.

**Root cause:** Treating the implementation and verification as finished once code passed, while leaving the local coordination artifacts stale. The earlier lesson covered the case where a status line said `complete` but checkboxes were blank; this failure was the inverse: source truth moved on while the plan files still advertised unstarted work.

**Prevention:** When a plan audit determines work is already implemented, immediately reconcile the task file in the same turn: set `Status:` to `complete` only when every required checkbox is true, set `Status: in-progress` when any task remains, tick only boxes backed by live file or command evidence, and add a short `## Completion Evidence` or `## Progress Notes` section naming the checked anchors. Do not end with a recommendation like "mark this complete later" when the evidence is already in hand.

## Lesson: Adding a rule cascades through fixtures, goldens, and existing tests

**Created:** 2026-05-11 (M31)

**What happened:** M31 added six new rules: `modernisation.phpdoc-mixed-overuse` (phase 1) and four `docs.missing-*-phpdoc` rules plus `design.single-implementor-interface` (phases 2 + 3). After phase 2 implementation the test suite went red with seven failures even though the new rules' own unit tests passed: existing fixtures in `tests/Fixtures/Source/Code/OrderCalculator.php` and `tests/Fixtures/Source/mixed/alpha.php` had no class-level or file-level docblocks, so the new docs rules added findings the existing CLI/registry tests did not expect (baselined count `1` became `3`, the RuleRegistry test's expected `lines: 19` became `26` after the docblock added 7 lines, golden snapshots stopped matching). Each fixture had been deliberately authored to fire exactly one rule for the older tests. The fix was per-fixture: add docblocks to keep the originally-targeted rule the only one firing, regenerate the goldens (`text-warning.txt`, `json-warning.json`), and update inline line-number expectations in `tests/Console/GruffCliTest.php` and `tests/Rule/RuleRegistryTest.php`.

**Root cause:** Fixtures encoded "this file produces exactly N findings" as an implicit invariant of the test, not just of the fixture. Adding rules that legitimately fire on those fixtures broke that invariant. Goldens are even more brittle: they bake in line numbers, file lengths, and grade-pillar scores that shift the moment a new finding is recorded.

**Prevention:** When adding a new rule, plan for three side-effects beyond the rule's own test:
1. **Existing fixtures that should still produce N findings.** Audit every fixture under `tests/Fixtures/` for files that could newly fire the rule, and add the minimum decoration (docblock, type hint, parameter rename) needed to preserve the test's invariant. Touch the fixture, not the test, so the test's narrative ("baseline workflow with one finding") survives.
2. **Goldens under `tests/Fixtures/Cli/Golden/`.** They will need regeneration: `php bin/gruff-php analyse <fixture> --config <golden's config> --format text > tests/Fixtures/Cli/Golden/<name>.txt` (same for `--format json`). Verify the diff is exactly the new rule's contribution and no stray drift.
3. **Inline expected-value assertions in `tests/Console/` and `tests/Rule/`.** Search for the affected fixture's filename plus literal numbers (line counts, line numbers) before assuming the file is unaffected. The `RuleRegistry::analyse` finding count and the file-length metadata `lines` value drift even when no test directly mentions the new rule.

For the dogfood snapshots also expect new findings on the gruff source tree itself (new rule fires on the new rule files) and on test files that don't have docblocks. Those are signal, not regression, as long as every diff is contained to files added in the same PR.

## Lesson: Respect explicit rule style even when it restates native syntax

**Created:** 2026-05-11

**What happened:** During external dogfooding, the user asked why private helper docblocks without `@return` were not being flagged. The agent initially pushed back that adding bare `@return string` / `@return bool` tags restated native signatures and created comment noise. The user clarified that gruff's project standard is stricter: `docs.missing-return-tag` must catch every documented method/function without `@return`, including private helpers.

**Root cause:** The agent applied a general PHPDoc minimalism preference instead of treating the user's explicit rule standard as the source of truth for this analyser. gruff is an opinionated scanner; some rules intentionally require documentation that another project might consider redundant.

**Prevention:** When the user specifies a rule standard, implement and verify that standard directly. Do not soften it based on generic style guidance unless the user asks for trade-offs. For PHPDoc rules in this repo, preserve the rule contract in tests using examples from both public methods and private helpers so future agents cannot narrow behavior back to "public contract only" or "only when the native signature is insufficient."

## Lesson: Reviewer "outside diff" coverage is not exhaustive — sweep the whole repo before reporting scope

**Created:** 2026-05-25

**What happened:** Reviewing PR #6 (the `naming.parameter-type-name` retirement), CodeRabbit's "outside diff range" comments flagged two stale rule-count references:

```text
docs/rules.md               section heading  ### `naming` (12)
.goat-flow/architecture.md  prose            "exposes 120 rule ids"
```

The agent's initial evaluation took those two as the complete drift set and quoted scope on that basis. A real double-check pass found three additional parallel stamps no reviewer had flagged:

```text
README.md   pillar tally row          | `naming` | 12 |
README.md   prose                     "120 rules across 11 pillars"
```

Plus an entirely separate drift pattern that no reviewer surfaced at all: the user's earlier `gruff.summary.v1` → `v2` bump in `src/Command/SummaryCommand.php` had left `docs/gruff-cli-summary.md` (three lines including a literal JSON example) and `.goat-flow/architecture.md` ("gruff.summary.v1 digest") advertising the pre-bump constant.

**Root cause:** Treating an AI reviewer's flagged-file list as the canonical drift surface. Reviewers like CodeRabbit and Codex scan the PR diff plus a few "outside diff" candidates; files unrelated to the PR's touched paths are invisible to them. A drift pattern that exists in the PR almost always exists in untouched files too, because the underlying trap is structural ("the same fact is stamped in N places"), not localised.

**Prevention:** When a reviewer flags a stale count, schema-version reference, or rule mention at one location, do not just fix that location. Grep the whole repo for the same pattern and report the full hit list before agreeing on scope. The canonical stamp maps live in the footguns:
- For rule-count drift, see `.goat-flow/footguns/rules.md` (search: `Retiring a rule leaves stale count references`).
- For schema-version drift, see `.goat-flow/footguns/schemas.md` (search: `Schema-version strings are stamped`).
Quoting honest scope sets the user's expectation correctly; understating it forces a second turn of "you missed X, Y, Z" and erodes trust in the agent's audit.

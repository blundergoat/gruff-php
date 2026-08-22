---
category: commands
last_reviewed: 2026-08-22
---

# CLI Command Footguns

## Footgun: Side-effect prompts placed before forwarded-option validation mutate the project on bad inputs

**Status:** active | **Created:** 2026-05-24 | **Evidence:** OBSERVED

`src/Cli/Command/ReportCommand.php` (search: `MissingConfigPrompt::maybeOffer`) validates its own `--output` directory before firing the init prompt, but the analyse-level options it forwards (`--format`, `--fail-on`, `--mutation-budget`, threshold flags) are not validated until the analyse subprocess runs at `src/Cli/Command/ReportCommand.php` (search: `analyseCommand`). A typo like `php bin/gruff-php report --fail-on bogus` in an interactive empty-config repo fires the prompt and (if accepted) writes `.gruff-php.yaml` before the subprocess rejects the value and exits `INVALID`. `AnalyseCommand`, `DashboardCommand`, and `SummaryCommand` already validate every option they own before `MissingConfigPrompt::maybeOffer` is reached.

**Evidence:** PR #3 review (Codex P2 × 3, since narrowed). Reproduce by running `php bin/gruff-php report --fail-on bogus` in an empty-config TTY repo and answering `y` — `.gruff-php.yaml` is written even though the analyse subprocess exits `INVALID` immediately after. The `analyse` and `dashboard` shapes from the original Codex report have been resolved. Recurrence (2026-07-03): the 0.5.0 report/analyse option-parity work initially added the `--profile security --include-rule <non-security>` rejection only on the analyse side, so report re-triggered this exact footgun for the new surface; an external review caught it, and `ReportCommand::profileIncludeUsageError` now pre-validates that combination before the prompt (rule-id filters were already pre-validated). Still unvalidated pre-prompt: `--format`, `--fail-on`, `--mutation-budget`, and other enum/threshold values.

**Prevention:** Any prompt that performs a filesystem side effect must run after all input validation completes — including validation done by a delegated subprocess. For commands that forward options to another command, either pre-validate the forwarded options locally before the prompt, or move the prompt past the subprocess invocation so the side effect only runs once the subprocess has accepted the inputs. The pattern file `.goat-flow/learning-loop/patterns/commands.md` records the canonical execute() order.

## Footgun: Finding-scope filters must treat an empty target set as drop-all, not pass-through

**Status:** active | **Created:** 2026-06-04 | **Evidence:** OBSERVED

`src/Cli/Command/AnalysisFindingSupport.php` holds sibling finding filters with deliberately different — and easy-to-confuse — empty-set semantics. `filterFindingsToChangedFiles` (search: `intentional drop-all`) returns `[]` on an empty changed set because "nothing changed" means nothing qualifies. `filterProjectRuleFindingsToFiles` (search: `filterProjectRuleFindingsToFiles`) originally short-circuited `if ($projectRuleIds === [] || $filePaths === []) return $findings;`, returning ALL findings when the requested path set discovered zero files. Because the legacy pipeline runs project rules over the whole-tree context regardless of the narrow request (`src/Rules/RuleRegistry.php`, search: `$projectUnits ?? $units`), a scoped run whose path matched no source files (e.g. `analyse some/dir-with-no-php --diff-vs main` with a project rule like `dead-code.unused-internal-class`) leaked whole-repo project findings into a run the user scoped to nothing.

**Evidence:** PR #8 review (CodeRabbit, "Don't treat an empty discovered-file set as unscoped"). Both callers pass `AnalysisSourceSet::displayPaths()` (search: `displayPaths`), which is empty exactly when the requested paths discovered no files. The fix drops project-rule findings on an empty set (search: `nothing is in scope`) while still returning unchanged when there are simply no project rules to scope. `tests/Command/AnalysisFindingSupportTest.php` (search: `WhenNoFilesDiscovered`) locks the behaviour.

**Prevention:** For any filter whose job is "keep findings inside scope set S", an empty S means "nothing is in scope" → drop the in-scope-only findings, not "no filter" → keep everything. Only return the input unchanged when the FILTER itself is inactive (no rule ids, no allowlist) — a different condition from an empty scope set. When adding a finding filter, write the empty-scope-set case as an explicit test before the happy path; the `=== [] return $findings` shortcut reads as a harmless guard but silently inverts the filter.

## Footgun: The result cache keys on tool version + config + rule set, not rule source, so a rule-LOGIC change does not invalidate cached findings

**Status:** active | **Created:** 2026-06-14 | **Evidence:** OBSERVED

`src/Engine/Cache/AnalysisFingerprint.php` (search: `forRun`) builds the per-file cache key from the resolved config, the enabled rule ids, and the gruff version (search: `toolVersion`, which writes `'version' => $toolVersion`) — never the rule classes' source. This is correct for end users because every release bumps `Application::VERSION`, which invalidates all entries. But while iterating on a rule's logic *within one version* (the common dev/validation loop), re-running `analyse` against a path that already has a warm `.gruff-cache` returns the OLD findings: same file content + same version + same enabled rules hashes to the same key, so the changed rule logic never re-runs. Observed while validating the guard-clause classifier change (later committed as `84d196d`) — a re-scan of a previously-scanned fixture reported the stale `severity: advisory` / `complexityShape: flat-guard-clauses`, while `--no-cache` on the same path reported the corrected `warning` / `branching`. (ADR-020 documents the cache.)

**Prevention:** Pass `--no-cache` whenever you validate a rule-LOGIC change by re-scanning a path that may have a warm cache (your own prior scans, or a repo's checked-in `.gruff-cache`). Unit tests bypass the cache entirely (they call `$rule->analyse(...)` directly), so this only bites CLI/real-repo validation. Do not bump `Application::VERSION` just to bust the cache for dev iteration — `--no-cache` is the right tool; the version bump is for releases. The preflight full-project scan (`scripts/preflight-checks.sh`, search: `analyse --fail-on advisory --no-cache`) now passes `--no-cache` for exactly this reason, so the release gate re-runs every rule fresh and can never pass or fail on stale findings — added 2026-06-14 after a readonly-rule fix that cleared 3 `size.parameter-count` errors still showed all 3 under a warm-cache preflight (`analyse --no-cache` confirmed 0). But ad-hoc `analyse` runs and your own dev re-scans still reuse the warm cache, so keep passing `--no-cache` there; CI is unaffected either way (cold cache).

## Footgun: Ignored scans can exit zero: `--include-ignored` bypasses defaults, not configured `paths.ignore`

**Status:** active | **Created:** 2026-06-14 | **Evidence:** ACTUAL_MEASURED
**Decision changed:** Inspect `ignoredPathDetails.source` and prove `summary.filesParsed > 0` before trusting a scan; choose the remedy for the reported ignore layer.
**Trigger phase:** VERIFY
**Incident count:** 3 | **Latest occurrence:** 2026-08-22

`src/Engine/Source/PathIgnoreResolver.php` (search: `Configured paths.ignore wins first and unconditionally`) applies configured `paths.ignore` patterns before it considers `--include-ignored`. The flag bypasses built-in default and generated exclusions only. A scan excluded by project config therefore still exits zero with the flag.

The default-ignore variant affects corpora under `.goat-flow/scratchpad`: scanning the bundled Shopware source parsed 0 files until `--include-ignored` was added, after which it parsed 7,147. The configured-ignore variant recurred during the 0.5.2 substantive-line proof. `.gruff-php.yaml` (search: `'tests/Fixtures/**'`) excluded `tests/Fixtures/Source/mixed/alpha.php`; scans both with and without `--include-ignored` reported `filesParsed: 0`. Passing the existing narrow config `tests/Fixtures/Config/file-length-warning.yaml` parsed one file and reported 11 substantive lines.

The third incident was the M11 scan-cost calibration. `src/Engine/Source/PathIgnoreResolver.php`
(search: `IGNORED_DIRECTORIES`) ignores any path segment named `tmp`, as well as `cache`, `build`,
`dist`, `generated`, `coverage`, `vendor`, and `node_modules`. A synthetic `.php` probe below
`/tmp` therefore returned `empty-analysis`, `ignoredPaths: 1`, and pattern `tmp`; the plausible
elapsed time was pure startup cost. Moving the probe to a non-ignored external scratch root and
asserting that the report actually analysed a file produced the valid cost series.

**Prevention:** Read `ignoredPathDetails.source` before changing flags. Use `--include-ignored` for `default` or `generated` exclusions. For a `config` exclusion, pass an explicit config that admits the target, or use `--no-config` only when the run is deliberately calibrating registry defaults. Treat an exit-zero scan as unproven until `summary.filesParsed > 0`; target a corpus source subtree so its dependency directories stay out.

## Footgun: A score withheld as inapplicable still reaches every value derived from it

**Status:** active | **Created:** 2026-08-16 | **Evidence:** ACTUAL_MEASURED
**Decision changed:** When suppressing a report value because discovery found no evidence, enumerate every downstream consumer of the same value rather than guarding the one that renders it.
**Trigger phase:** ACT

`ScoreCalculator` scores an empty finding set as 100, so a run that discovers no files produces a full-marks score carrying no evidence. `src/Cli/Command/AnalyseCommand.php` guards that synthetic value in three separate places - `scoreForDiscoveredFiles` drops it from the report, `recordTrendWhenFilesDiscovered` keeps it out of history, and `withEmptyAnalysisDiagnostic` tells the user the score is not applicable - because each consumer needs its own guard. A fourth consumer read the same value through a different variable: `$reviewScore` fed `src/Cli/Command/BranchReviewBuilder.php` (search: `deltaVersusBase`), which subtracted a real base score from the synthetic 100 and published the difference.

**Evidence:** Measured 2026-08-16 on `e8bd21a`, reproduced with the fixture behind `tests/Review/AgentWorkflowCliTest.php` (search: `testBranchReviewDeletedFileReportsRemovedFindings`). A branch deleting its only changed PHP file, scanned with `--diff-vs=HEAD --changed-only`, reported `filesDiscovered: 0`, diagnostic `empty-analysis`, no `score` key, and `deltaScore: 11.200000000000003`. The TEXT report printed "the score is not applicable" and `Score delta: +11.20` eleven lines apart. Codex flagged it as P2 on PR #13.

**Prevention:** Grep the suppressed value's producer for every read before adding the guard - here `$score->composite->score` and the separate `$reviewScore` recomputation are two reads of one calculator. A guard that lives at the render site protects one surface; a guard that lives at the value's source protects all of them. Suppressing at the source runs the mirror risk, so audit the renderers too: a type may already be nullable while nothing in production ever produced null, which leaves consumers untested against it. `deltaScore` was `?float` throughout `src/Results/Review/` yet only unit tests ever built one, and `src/Output/Reporter/TextReporter.php` (search: `Score delta`) is its only renderer and already guarded. Derived counts are not affected: the review's `perRuleDelta` block counts findings rather than pricing them, so it still reports what a deletion removed.

## Resolved Entries

## Footgun: Editing above a baseline-suppressed finding resurfaced it as a new finding

**Status:** resolved | **Created:** 2026-05-31 | **Resolved:** 2026-07-03 | **Evidence:** OBSERVED

Before baseline v2 (ADR-029), the default-applied `gruff-baseline.json` matched accepted-debt findings to live findings purely by `fingerprint`, which hashes the finding's `line`/`endLine`/`column`. Inserting or deleting any line *above* a suppressed finding shifted its line, changed its fingerprint, un-matched the baseline entry, and the previously-accepted finding re-appeared as `new` (failing `--fail-on advisory`). During the 0.3.0 self-scan cleanup, four accepted-debt findings (`PhpDocMixedOveruseRule::hasSignatureBroadTypeCoverage` cognitive, `isPreciseArrayShape` regex-comment, `topLevelColonIndex` missing-return, `AnalyseCommandOptions::diffMode` missing-return) each resurfaced this way after an unrelated edit earlier in the same file.

**Evidence:** Reproduced 2026-07-03 on the pre-fix binary: one line inserted above the second of two accepted findings reported `new=1, unchanged=1, absent=1`.

**Resolution:** `gruff.baseline.v2` (ADR-029) matches by grouped `(file, ruleId, message)` counts in `src/Results/Baseline/BaselineFilter.php` (search: `byGroup`), so line numbers no longer participate in baseline matching; the same edit now reports `new=0, unchanged=N, absent=0`. Legacy v1 files fail closed with a regenerate instruction.

**Prevention:** Baselines survive line shifts now, but the match key includes the message: rewording a rule message invalidates its groups (regenerate after such releases), and a fix-one/add-one swap within the same `(file, ruleId, message)` group is invisible while the live count stays within the accepted count.

## Footgun: A narrow-path `analyse`/`hook` re-parsed the whole project when built-in project rules were enabled

**Status:** resolved | **Created:** 2026-06-10 | **Resolved:** 2026-06-10 | **Evidence:** ACTUAL_MEASURED

Before v0.4.0, the default `.gruff-php.yaml` enabled four built-in `ProjectRuleInterface` rules (`dead-code.unused-internal-class`, `dead-code.unused-internal-constant`, `dead-code.unused-internal-function`, `design.single-implementor-interface`). Narrow `analyse` and `hook` runs therefore paid whole-project context cost even when the requested path was one file, and the result cache stayed unavailable because project rules require cross-file context.

**Evidence:** ACTUAL_MEASURED 2026-06-10, synthetic git project, PHP 8.3.30 (NTS), WSL2, gruff-php 0.3.1. `analyse OneSmallFile.php` (one 19-line class): 0.33s user @500 files -> 1.20s user @2000 files. `hook --changed-ranges 1-60 OneSmallFile.php` @2000 files: 1.20s user, identical to `analyse`. Disabling only the four project rules: 1.20s -> 0.04s user with the same 10 findings. `--print-runtime --runtime-mode detailed` reported `filesParsed: 1` while the legacy project-context path had parsed the larger tree.

**Resolution:** ADR-026 retired those four built-in project rules, and the default `.gruff-php.yaml` no longer enables them. The `ProjectRuleInterface` seam remains available for future cross-file rules, but the built-in default path no longer triggers this cost.

**Prevention:** Treat any future `ProjectRuleInterface` as a whole-project-cost feature for narrow runs. Before adding one, measure narrow-path latency, decide whether the rule belongs in default config, and prefer one shared cross-file index over one-index-per-rule.

## Footgun: Config-less `analyse` blocked or wrote config on non-TTY stdin without `-n`

**Status:** resolved | **Created:** 2026-06-10 | **Resolved:** 2026-06-10 | **Evidence:** ACTUAL_MEASURED

Before v0.4.0, `src/Cli/Command/MissingConfigPrompt.php` (search: `!$input->isInteractive()`) relied on Symfony's interactive flag for the init y/N prompt. In this Symfony/PHP setup, that flag could stay true for a non-TTY pipe/socket stdin even when `stream_isatty(STDIN)` was false, so config-less `analyse` could block waiting for prompt input or accept a piped leading `y` and write `.gruff-php.yaml`.

**Evidence:** ACTUAL_MEASURED 2026-06-10, PHP 8.3.30. In a config-less git repo, `sleep 20 | timeout 8 php bin/gruff-php analyse --format json X.php` exited 124 on the prompt; adding `-n` completed. `printf 'y\n' | php bin/gruff-php analyse --format json X.php` accepted the prompt and wrote `.gruff-php.yaml`.

**Resolution:** `src/Cli/Command/MissingConfigPrompt.php` (search: `hasAnswerableInput`) now skips the prompt for machine-readable formats and for the real STDIN non-TTY case when Symfony has no explicit test stream. `src/Cli/Command/ReportCommand.php` (search: `'--no-interaction'`) also forwards non-interaction to its child analyse process.

**Prevention:** Do not rely on `InputInterface::isInteractive()` alone for prompt safety. Any command that can emit machine-readable output or run under hooks/jobs must either pass `-n`/`--no-interaction`, provide an explicit stream test seam, or check real TTY state before prompting.

## Footgun: Dispatching a sub-command loses the caller's project-root context

**Status:** resolved | **Created:** 2026-05-24 | **Resolved:** 2026-05-24 | **Evidence:** OBSERVED

`src/Cli/Command/MissingConfigPrompt.php` (search: `$symfonyApplication->find('init')`) dispatched `init` with a bare `ArrayInput(['command' => 'init'])`. `src/Cli/Command/InitCommand.php` previously derived its target solely from the process CWD via `getcwd()` and exposed no `--project-root` option to override it. `src/Cli/Dashboard/DashboardCommand.php` (search: `$dashboardStateFactory->initialProjectRoot($input, $cwd)`) can resolve a different project root from `--project`/`--project-root`, so accepting the prompt during `dashboard --project /other/repo` wrote `.gruff-php.yaml` into the launching shell directory instead of the project the dashboard was actually serving. `AnalyseCommand`, `SummaryCommand`, and `ReportCommand` all happened to derive `$projectRoot` from `getcwd()` themselves, so the dispatch landed in the right directory by accident — the alignment was not guaranteed by the prompt's API.

**Evidence:** PR #3 review flagged this independently from three agents (Codex P1, CodeRabbit 🔴 Critical, Copilot). `MissingConfigPrompt::maybeOffer()` used `$projectRoot` for the existence check, then ignored it when dispatching.

**Resolution:** `src/Cli/Command/MissingConfigPrompt.php` (search: `'--project-root' => $projectRoot`) now passes the resolved project root in the dispatched `ArrayInput`, and `src/Cli/Command/InitCommand.php` (search: `->addOption('project-root'`) declares a `--project-root` option whose handler overrides the `getcwd()` default.

**Prevention:** When dispatching one console command from another, never pass a bare `ArrayInput` if the inner command takes its target from `getcwd()`. Either add a `--project-root` (or equivalent) option to the inner command and pass it in the `ArrayInput`, or wrap the dispatch in `chdir($projectRoot)` with the restore in a `finally` block. Audit every future `$application->find(...)->run(...)` site against this rule — the trap reappears for any command that has filesystem side effects and any caller that can address a non-CWD target.

## Footgun: Interactive prompts written to $output corrupt machine-readable formats

**Status:** resolved | **Created:** 2026-05-24 | **Resolved:** 2026-05-24 | **Evidence:** OBSERVED

`src/Cli/Command/MissingConfigPrompt.php` (search: `$questionHelper->ask`) wrote the confirmation prompt to the same `OutputInterface` the calling command later used for its primary payload. For `analyse --format=json`, `summary --format=json`, `report --format=json|html`, and `analyse --format=sarif`, the prompt text — and any accepted `init` command output — was prepended to the structured payload, leaving stdout unparseable. The interactive gate at `MissingConfigPrompt::maybeOffer()` (search: `!$input->isInteractive()`) only checks STDIN, so the common dev pattern `gruff-php analyse --format=json > out.json` keeps interactive STDIN while redirecting STDOUT and still fires the prompt.

**Evidence:** PR #3 review (Codex P1 × 3 across `MissingConfigPrompt`, `ReportCommand`, and the analyse/summary call sites; Copilot also flagged it). Trigger: any machine-readable format with TTY STDIN and no existing project config.

**Resolution:** `src/Cli/Command/MissingConfigPrompt.php` (search: `promptOutput`) routes the confirmation prompt and any dispatched init output to `ConsoleOutputInterface::getErrorOutput()` when the caller exposes a separate error stream, falling back to the supplied `$output` only for plain `OutputInterface` callers. Machine-readable stdout payloads stay parseable.

**Prevention:** Route interactive prompts through `ConsoleOutputInterface::getErrorOutput()` (or a fallback `StreamOutput(STDERR)`) so the primary `$output` stream stays parseable. Alternatively, gate the prompt on the requested output format and only fire when the active format is human-readable text. Apply the same rule to any future onboarding, deprecation, or recoverable-error prompt that runs before a command writes its payload — a prompt on stdout is silently destructive for every downstream consumer that pipes the command into `jq`, a SARIF uploader, or a file.

## Footgun: Parallel "config present" guards drift between caller and writer

**Status:** resolved | **Created:** 2026-05-24 | **Resolved:** 2026-05-24 | **Evidence:** OBSERVED

`src/Cli/Command/MissingConfigPrompt.php` correctly checked both `.gruff-php.yaml` and `.gruff.yaml` when deciding whether to skip the init prompt. `src/Cli/Command/InitCommand.php` previously held a direct `is_file($targetPath) && !$force` guard that only checked `.gruff-php.yaml`. The two encodings of "what counts as existing config" disagreed: a project on legacy `.gruff.yaml` running `gruff-php init` directly silently wrote the preferred config alongside it and flipped future analyse/summary runs over to generated defaults instead of the project's configured behavior.

**Evidence:** PR #3 review (Codex P1). The prompt skip-gate and the init overwrite guard both answered "is there already a config?" but used different file sets. `ConfigLoader::DEFAULT_CONFIG_FILE` and `ConfigLoader::LEGACY_DEFAULT_CONFIG_FILE` already centralised the file names; the predicate that used them did not.

**Resolution:** `src/Engine/Config/ConfigLoader.php` (search: `public static function hasProjectConfig`) centralises the predicate over both filenames, and every caller now routes through it: the prompt skip-gate (`MissingConfigPrompt::maybeOffer`) and the init overwrite guard (`InitCommand::guardExistingConfig`, which also refuses on a legacy `.gruff.yaml` without `--force`).

**Prevention:** Whenever two methods encode the same predicate over the same constants, treat that as a drift waiting to happen and consolidate before the next entry point is added. New callers that need to know "is there a project config?" must call `ConfigLoader::hasProjectConfig()` rather than re-implementing the `is_file()` check, even if it looks like a one-liner.

## Footgun: Empty option strings need explicit normalization to null

**Status:** resolved | **Created:** 2026-05-24 | **Resolved:** 2026-05-24 | **Evidence:** OBSERVED

`src/Cli/Command/SummaryCommand.php` originally returned the raw option value whenever it was a string, including `''`. `src/Cli/Dashboard/DashboardStateFactory.php` (search: `public function optionalStringOption`) did the consistent thing: `is_string($value) && $value !== '' ? $value : null`. `src/Cli/Command/ReportCommand.php` (search: `private function optionalStringOption`) was another correct example. The mismatch meant `summary --config=""` passed the empty string through to `hasConfigConflict()` and `ConfigLoader`, which treat empty differently from null and produced subtly wrong behaviour (false conflict checks, skipped missing-config prompts).

**Evidence:** PR #3 review (CodeRabbit, outside-diff). Three commands performed the same option-reading work three different ways; every new command that added a `--config`-style string option was one Copy/Paste away from the wrong variant.

**Resolution:** `src/Cli/Command/SummaryCommand.php` (search: `private function configPath(InputInterface $input): ?string`) now matches the `is_string($value) && $value !== ''` shape used by the other call sites, so `--config=""` is normalised to null at the read site.

**Prevention:** Treat an empty-string return from `InputInterface::getOption()` as null at every read site. The minimum helper is `is_string($value) && $value !== '' ? $value : null`. Better: extract a shared `OptionReader::optionalString(InputInterface $input, string $name): ?string` helper and route every `--config`, `--baseline`, `--output`, `--host`, and similar string option through it. Audit any new command that reads a string-valued option against this rule during review — the inconsistency is invisible until a user passes `--name=""`.

---
category: commands
last_reviewed: 2026-05-24
---

# CLI Command Footguns

## Footgun: Dispatching a sub-command loses the caller's project-root context

**Status:** active | **Created:** 2026-05-24 | **Evidence:** OBSERVED

`src/Command/MissingConfigPrompt.php` (search: `$symfonyApplication->find('init')`) dispatches `init` with a bare `ArrayInput(['command' => 'init'])`. `src/Command/InitCommand.php` (search: `$projectRoot = getcwd()`) derives its target solely from the process CWD and exposes no `--project-root` option to override it. `src/Command/DashboardCommand.php` (search: `$dashboardStateFactory->initialProjectRoot($input, $cwd)`) can resolve a different project root from `--project`/`--project-root`, so accepting the prompt during `dashboard --project /other/repo` writes `.gruff-php.yaml` into the launching shell directory instead of the project the dashboard is actually serving. `AnalyseCommand`, `SummaryCommand`, and `ReportCommand` all happen to derive `$projectRoot` from `getcwd()` themselves, so the dispatch lands in the right directory by accident — the alignment is not guaranteed by the prompt's API.

**Evidence:** PR #3 review flagged this independently from three agents (Codex P1, CodeRabbit 🔴 Critical, Copilot). `MissingConfigPrompt::maybeOffer()` (search: `hasProjectConfig($projectRoot)`) uses `$projectRoot` for the existence check, then ignores it when dispatching.

**Prevention:** When dispatching one console command from another, never pass a bare `ArrayInput` if the inner command takes its target from `getcwd()`. Either add a `--project-root` (or equivalent) option to the inner command and pass it in the `ArrayInput`, or wrap the dispatch in `chdir($projectRoot)` with the restore in a `finally` block. Audit every future `$application->find(...)->run(...)` site against this rule — the trap reappears for any command that has filesystem side effects and any caller that can address a non-CWD target.

## Footgun: Interactive prompts written to $output corrupt machine-readable formats

**Status:** active | **Created:** 2026-05-24 | **Evidence:** OBSERVED

`src/Command/MissingConfigPrompt.php` (search: `$questionHelper->ask`) writes the confirmation prompt to the same `OutputInterface` the calling command later uses for its primary payload. For `analyse --format=json`, `summary --format=json`, `report --format=json|html`, and `analyse --format=sarif`, the prompt text — and any accepted `init` command output — is prepended to the structured payload, leaving stdout unparseable. The interactive gate at `MissingConfigPrompt::maybeOffer()` (search: `!$input->isInteractive()`) only checks STDIN, so the common dev pattern `gruff-php analyse --format=json > out.json` keeps interactive STDIN while redirecting STDOUT and still fires the prompt.

**Evidence:** PR #3 review (Codex P1 × 3 across `MissingConfigPrompt`, `ReportCommand`, and the analyse/summary call sites; Copilot also flagged it). Trigger: any machine-readable format with TTY STDIN and no existing project config.

**Prevention:** Route interactive prompts through `ConsoleOutputInterface::getErrorOutput()` (or a fallback `StreamOutput(STDERR)`) so the primary `$output` stream stays parseable. Alternatively, gate the prompt on the requested output format and only fire when the active format is human-readable text. Apply the same rule to any future onboarding, deprecation, or recoverable-error prompt that runs before a command writes its payload — a prompt on stdout is silently destructive for every downstream consumer that pipes the command into `jq`, a SARIF uploader, or a file.

## Footgun: Side-effect prompts placed before input validation mutate the project on bad inputs

**Status:** active | **Created:** 2026-05-24 | **Evidence:** OBSERVED

`src/Command/AnalyseCommandSetupBuilder.php` (search: `MissingConfigPrompt::maybeOffer`) calls the init prompt at line 48 — before `buildSetup()` validates `--format`, `--fail-on`, `--mutation-budget`, and option compatibility. `src/Command/DashboardCommand.php` (search: `MissingConfigPrompt::maybeOffer`) calls it before `port()` and `scanTimeout()` validate `--port` and `--scan-timeout`. `src/Command/ReportCommand.php` (search: `MissingConfigPrompt::maybeOffer`) calls it before the subprocess that performs analyse-level validation. A typo like `php bin/gruff-php analyse --fail-on bogus` or `dashboard --port nope` in an interactive empty-config repo fires the prompt and (if accepted) writes `.gruff-php.yaml` before the command exits `INVALID`. `src/Command/SummaryCommand.php` (search: `MissingConfigPrompt::maybeOffer`) is the counter-example that does the right thing: `summaryFormat()`, `topLimit()`, and `hasConfigConflict()` all run before the prompt.

**Evidence:** PR #3 review (Codex P2 × 3). Reproduce by running `php bin/gruff-php analyse --fail-on bogus` in an empty-config TTY repo and answering `y` — the file is written even though the command exits `INVALID` immediately after.

**Prevention:** Any prompt that performs a filesystem side effect must run after all input validation completes. The validation-then-side-effect ordering is the same shape `SummaryCommand::execute` already uses. For analyse, dashboard, and report, move `MissingConfigPrompt::maybeOffer` to the bottom of validation — after option parsing and threshold checks, but before the work that actually needs config. When adding a new console command that fires a side-effect prompt, write the execute body in this exact order: read raw input → validate types/formats → check option conflicts → run prompts/side effects → run main work → write output. The pattern file `.goat-flow/patterns/commands.md` records the canonical shape.

## Footgun: Parallel "config present" guards drift between caller and writer

**Status:** active | **Created:** 2026-05-24 | **Evidence:** OBSERVED

`src/Command/MissingConfigPrompt.php` (search: `LEGACY_DEFAULT_CONFIG_FILE`) correctly checks both `.gruff-php.yaml` and `.gruff.yaml` when deciding whether to skip the init prompt. `src/Command/InitCommand.php` (search: `if (is_file($targetPath) && !$force)`) only guards on `.gruff-php.yaml`. The two encodings of "what counts as existing config" already disagree: a project on legacy `.gruff.yaml` running `gruff-php init` directly silently writes the preferred config alongside it and flips future analyse/summary runs over to generated defaults instead of the project's configured behavior.

**Evidence:** PR #3 review (Codex P1). Compare `MissingConfigPrompt::hasProjectConfig()` against `InitCommand::execute` — they both answer "is there already a config?" but use different file sets. `ConfigLoader::DEFAULT_CONFIG_FILE` and `ConfigLoader::LEGACY_DEFAULT_CONFIG_FILE` already centralise the file names; the predicate that uses them does not.

**Prevention:** Centralise the "discoverable project config" check in one helper — for example a static method on `ConfigLoader`, `ConfigLoader::hasProjectConfig(string $projectRoot): bool` — and call it from every caller: the prompt's skip-gate, the init overwrite guard, dashboard state setup, and any future command that asks the same question. Whenever two methods encode the same predicate over the same constants, treat that as a drift waiting to happen and consolidate before the next entry point is added.

## Footgun: Empty option strings need explicit normalization to null

**Status:** active | **Created:** 2026-05-24 | **Evidence:** OBSERVED

`src/Command/SummaryCommand.php` (search: `private function configPath(InputInterface $input): ?string`) returns the raw option value whenever it is a string, including `''`. `src/Command/AnalyseCommandSetupBuilder.php` (search: `private function explicitConfigPath(InputInterface $input): ?string`) does the consistent thing: `is_string($rawConfigPath) && $rawConfigPath !== '' ? $rawConfigPath : null`. `src/Command/DashboardStateFactory.php` (search: `public function optionalStringOption`) is another correct example. The mismatch means `summary --config=""` passes the empty string through to `hasConfigConflict()` and `ConfigLoader`, which treat empty differently from null and produce subtly wrong behaviour (false conflict checks, skipped missing-config prompts).

**Evidence:** PR #3 review (CodeRabbit, outside-diff). Three commands already perform the same option-reading work three different ways; every new command that adds a `--config`-style string option is one Copy/Paste away from the wrong variant.

**Prevention:** Treat an empty-string return from `InputInterface::getOption()` as null at every read site. The minimum helper is `is_string($value) && $value !== '' ? $value : null`. Better: extract a shared `OptionReader::optionalString(InputInterface $input, string $name): ?string` helper and route every `--config`, `--baseline`, `--output`, `--host`, and similar string option through it. Audit any new command that reads a string-valued option against this rule during review — the inconsistency is invisible until a user passes `--name=""`.

---
category: commands
last_reviewed: 2026-05-24
---

# CLI Command Footguns

## Footgun: Side-effect prompts placed before forwarded-option validation mutate the project on bad inputs

**Status:** active | **Created:** 2026-05-24 | **Evidence:** OBSERVED

`src/Command/ReportCommand.php` (search: `MissingConfigPrompt::maybeOffer`) validates its own `--output` directory before firing the init prompt, but the analyse-level options it forwards (`--format`, `--fail-on`, `--mutation-budget`, threshold flags) are not validated until the analyse subprocess runs at `src/Command/ReportCommand.php` (search: `analyseCommand`). A typo like `php bin/gruff-php report --fail-on bogus` in an interactive empty-config repo fires the prompt and (if accepted) writes `.gruff-php.yaml` before the subprocess rejects the value and exits `INVALID`. `AnalyseCommand`, `DashboardCommand`, and `SummaryCommand` already validate every option they own before `MissingConfigPrompt::maybeOffer` is reached.

**Evidence:** PR #3 review (Codex P2 × 3, since narrowed). Reproduce by running `php bin/gruff-php report --fail-on bogus` in an empty-config TTY repo and answering `y` — `.gruff-php.yaml` is written even though the analyse subprocess exits `INVALID` immediately after. The `analyse` and `dashboard` shapes from the original Codex report have been resolved.

**Prevention:** Any prompt that performs a filesystem side effect must run after all input validation completes — including validation done by a delegated subprocess. For commands that forward options to another command, either pre-validate the forwarded options locally before the prompt, or move the prompt past the subprocess invocation so the side effect only runs once the subprocess has accepted the inputs. The pattern file `.goat-flow/patterns/commands.md` records the canonical execute() order.

## Resolved Entries

## Footgun: Dispatching a sub-command loses the caller's project-root context

**Status:** resolved | **Created:** 2026-05-24 | **Resolved:** 2026-05-24 | **Evidence:** OBSERVED

`src/Command/MissingConfigPrompt.php` (search: `$symfonyApplication->find('init')`) dispatched `init` with a bare `ArrayInput(['command' => 'init'])`. `src/Command/InitCommand.php` previously derived its target solely from the process CWD via `getcwd()` and exposed no `--project-root` option to override it. `src/Command/DashboardCommand.php` (search: `$dashboardStateFactory->initialProjectRoot($input, $cwd)`) can resolve a different project root from `--project`/`--project-root`, so accepting the prompt during `dashboard --project /other/repo` wrote `.gruff-php.yaml` into the launching shell directory instead of the project the dashboard was actually serving. `AnalyseCommand`, `SummaryCommand`, and `ReportCommand` all happened to derive `$projectRoot` from `getcwd()` themselves, so the dispatch landed in the right directory by accident — the alignment was not guaranteed by the prompt's API.

**Evidence:** PR #3 review flagged this independently from three agents (Codex P1, CodeRabbit 🔴 Critical, Copilot). `MissingConfigPrompt::maybeOffer()` used `$projectRoot` for the existence check, then ignored it when dispatching.

**Resolution:** `src/Command/MissingConfigPrompt.php` (search: `'--project-root' => $projectRoot`) now passes the resolved project root in the dispatched `ArrayInput`, and `src/Command/InitCommand.php` (search: `->addOption('project-root'`) declares a `--project-root` option whose handler overrides the `getcwd()` default.

**Prevention:** When dispatching one console command from another, never pass a bare `ArrayInput` if the inner command takes its target from `getcwd()`. Either add a `--project-root` (or equivalent) option to the inner command and pass it in the `ArrayInput`, or wrap the dispatch in `chdir($projectRoot)` with the restore in a `finally` block. Audit every future `$application->find(...)->run(...)` site against this rule — the trap reappears for any command that has filesystem side effects and any caller that can address a non-CWD target.

## Footgun: Interactive prompts written to $output corrupt machine-readable formats

**Status:** resolved | **Created:** 2026-05-24 | **Resolved:** 2026-05-24 | **Evidence:** OBSERVED

`src/Command/MissingConfigPrompt.php` (search: `$questionHelper->ask`) wrote the confirmation prompt to the same `OutputInterface` the calling command later used for its primary payload. For `analyse --format=json`, `summary --format=json`, `report --format=json|html`, and `analyse --format=sarif`, the prompt text — and any accepted `init` command output — was prepended to the structured payload, leaving stdout unparseable. The interactive gate at `MissingConfigPrompt::maybeOffer()` (search: `!$input->isInteractive()`) only checks STDIN, so the common dev pattern `gruff-php analyse --format=json > out.json` keeps interactive STDIN while redirecting STDOUT and still fires the prompt.

**Evidence:** PR #3 review (Codex P1 × 3 across `MissingConfigPrompt`, `ReportCommand`, and the analyse/summary call sites; Copilot also flagged it). Trigger: any machine-readable format with TTY STDIN and no existing project config.

**Resolution:** `src/Command/MissingConfigPrompt.php` (search: `promptOutput`) routes the confirmation prompt and any dispatched init output to `ConsoleOutputInterface::getErrorOutput()` when the caller exposes a separate error stream, falling back to the supplied `$output` only for plain `OutputInterface` callers. Machine-readable stdout payloads stay parseable.

**Prevention:** Route interactive prompts through `ConsoleOutputInterface::getErrorOutput()` (or a fallback `StreamOutput(STDERR)`) so the primary `$output` stream stays parseable. Alternatively, gate the prompt on the requested output format and only fire when the active format is human-readable text. Apply the same rule to any future onboarding, deprecation, or recoverable-error prompt that runs before a command writes its payload — a prompt on stdout is silently destructive for every downstream consumer that pipes the command into `jq`, a SARIF uploader, or a file.

## Footgun: Parallel "config present" guards drift between caller and writer

**Status:** resolved | **Created:** 2026-05-24 | **Resolved:** 2026-05-24 | **Evidence:** OBSERVED

`src/Command/MissingConfigPrompt.php` correctly checked both `.gruff-php.yaml` and `.gruff.yaml` when deciding whether to skip the init prompt. `src/Command/InitCommand.php` previously held a direct `is_file($targetPath) && !$force` guard that only checked `.gruff-php.yaml`. The two encodings of "what counts as existing config" disagreed: a project on legacy `.gruff.yaml` running `gruff-php init` directly silently wrote the preferred config alongside it and flipped future analyse/summary runs over to generated defaults instead of the project's configured behavior.

**Evidence:** PR #3 review (Codex P1). The prompt skip-gate and the init overwrite guard both answered "is there already a config?" but used different file sets. `ConfigLoader::DEFAULT_CONFIG_FILE` and `ConfigLoader::LEGACY_DEFAULT_CONFIG_FILE` already centralised the file names; the predicate that used them did not.

**Resolution:** `src/Config/ConfigLoader.php` (search: `public static function hasProjectConfig`) centralises the predicate over both filenames, and every caller now routes through it: the prompt skip-gate (`MissingConfigPrompt::maybeOffer`) and the init overwrite guard (`InitCommand::guardExistingConfig`, which also refuses on a legacy `.gruff.yaml` without `--force`).

**Prevention:** Whenever two methods encode the same predicate over the same constants, treat that as a drift waiting to happen and consolidate before the next entry point is added. New callers that need to know "is there a project config?" must call `ConfigLoader::hasProjectConfig()` rather than re-implementing the `is_file()` check, even if it looks like a one-liner.

## Footgun: Empty option strings need explicit normalization to null

**Status:** resolved | **Created:** 2026-05-24 | **Resolved:** 2026-05-24 | **Evidence:** OBSERVED

`src/Command/SummaryCommand.php` originally returned the raw option value whenever it was a string, including `''`. `src/Command/DashboardStateFactory.php` (search: `public function optionalStringOption`) did the consistent thing: `is_string($value) && $value !== '' ? $value : null`. `src/Command/ReportCommand.php` (search: `private function optionalStringOption`) was another correct example. The mismatch meant `summary --config=""` passed the empty string through to `hasConfigConflict()` and `ConfigLoader`, which treat empty differently from null and produced subtly wrong behaviour (false conflict checks, skipped missing-config prompts).

**Evidence:** PR #3 review (CodeRabbit, outside-diff). Three commands performed the same option-reading work three different ways; every new command that added a `--config`-style string option was one Copy/Paste away from the wrong variant.

**Resolution:** `src/Command/SummaryCommand.php` (search: `private function configPath(InputInterface $input): ?string`) now matches the `is_string($value) && $value !== ''` shape used by the other call sites, so `--config=""` is normalised to null at the read site.

**Prevention:** Treat an empty-string return from `InputInterface::getOption()` as null at every read site. The minimum helper is `is_string($value) && $value !== '' ? $value : null`. Better: extract a shared `OptionReader::optionalString(InputInterface $input, string $name): ?string` helper and route every `--config`, `--baseline`, `--output`, `--host`, and similar string option through it. Audit any new command that reads a string-valued option against this rule during review — the inconsistency is invisible until a user passes `--name=""`.

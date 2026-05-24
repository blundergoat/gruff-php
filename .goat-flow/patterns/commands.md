---
category: commands
last_reviewed: 2026-05-24
---

# CLI Command Patterns

## Pattern: Symfony Console execute() shape for commands that own side effects

**Created:** 2026-05-24

**Context:** Several gruff CLI commands accept user-supplied flags that can be malformed (`--fail-on bogus`, `--port nope`, `--scan-timeout xyz`) and also perform or dispatch filesystem side effects (writing `.gruff-php.yaml` via `init`, writing baseline files, writing report output). Without a consistent body order, prompts and dispatches that should only happen for valid invocations can fire before validation rejects the run, mutating the project on inputs that exit `INVALID`. PR #3 review of `MissingConfigPrompt` surfaced this for three of four call sites.

**Approach:** Write `execute()` for any command that owns side effects in this fixed order:

1. **Resolve environment** — read `getcwd()`, resolve `--project-root`, abort with FAILURE if the environment is unusable.
2. **Validate input types and formats** — parse `--format`, `--fail-on`, numeric ranges (`--port`, `--top`, `--scan-timeout`), and any other option whose shape can be wrong. Return `Command::INVALID` immediately if any value cannot be coerced.
3. **Check option conflicts** — apply cross-option rules (`--no-config` vs `--config`, mutually exclusive modes). Return `Command::INVALID` immediately on conflict.
4. **Run prompts and dispatched side effects** — only after the inputs are known to be coherent. A prompt that writes files (e.g. `MissingConfigPrompt::maybeOffer`) belongs here, never earlier.
5. **Load configuration** — `ConfigLoader::load`, registry setup, etc., now that the post-prompt filesystem state is settled.
6. **Run the main work** — analysis, scoring, report assembly.
7. **Write output** — `$output->write(...)` for the primary payload. Interactive prompts and progress chatter should have gone to STDERR earlier, never co-mingled with this stream.

`src/Command/SummaryCommand.php` (search: `summaryFormat($input, $output)`) is the canonical example: format → top limit → config conflict → prompt → registry → analysis → write. The three commands that violate the order are `AnalyseCommand` (via `AnalyseCommandSetupBuilder::build`), `DashboardCommand`, and `ReportCommand`; the violation shape and remediation are recorded in `.goat-flow/footguns/commands.md` under "Side-effect prompts placed before input validation mutate the project on bad inputs".

**Stream routing:** Symfony's `QuestionHelper` writes to whichever `OutputInterface` it receives. When the command can emit machine-readable payloads (`json`, `sarif`, `html`, `hotspot`), pass `ConsoleOutputInterface::getErrorOutput()` to prompts and dispatched progress so the primary `$output` stream stays parseable. The same rule applies to deprecation notices, recoverable-error messages, and any chatter from a delegated subprocess.

**Sub-command dispatch:** When using `$application->find('inner')->run(new ArrayInput([...]), $output)` to invoke another console command, the inner command runs in the current process's CWD. If the outer command has a non-CWD project root (`DashboardCommand` with `--project /other/repo`), either pass an explicit `--project-root` option in the `ArrayInput` (requiring the inner command to declare one) or wrap the call in `chdir($projectRoot)` with a `chdir($originalCwd)` in a `finally` block. Document the choice once and apply it everywhere; bare `ArrayInput(['command' => 'inner'])` is unsafe whenever the outer command can address a project root other than CWD.

**Verification:** For any new command that follows this pattern, add a CLI test under `tests/Console/` that:

- Sends a malformed value for each validated option and asserts `Command::INVALID` plus *no* filesystem change.
- Runs with `--no-interaction` to confirm prompts are correctly bypassed for non-interactive shells.
- Runs with a machine-readable format and a piped stdout; asserts the captured payload parses cleanly with no leading prompt or chatter.
- For dispatched sub-commands, sets a non-CWD project root and asserts the inner command's side effect lands in the right directory.

These four assertions catch every PR #3 review finding before they can recur.

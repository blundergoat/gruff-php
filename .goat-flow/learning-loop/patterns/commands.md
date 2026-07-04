---
category: commands
last_reviewed: 2026-07-03
---

# CLI Command Patterns

## Pattern: Split scaffold-via-`Yaml::dump` and rules-via-manual-string when init output needs per-rule comments

**Created:** 2026-05-25

**Context:** The `init` command originally built one PHP array (paths + allowlists + selection + rules) and round-tripped it through `Symfony\Component\Yaml\Yaml::dump()`. That works until you want a `# {description}` line above every rule entry — Symfony's YAML dumper has no API for emitting comments mid-document. Naive workarounds (string-replace `ruleId:` → `# desc\nruleId:` after the fact) are brittle because the dumper may quote keys with dots, vary indent on nested options, or change formatting across versions.

**Approach:** Render the file in two pieces and concatenate.

1. **Scaffold via `Yaml::dump`** — keep the existing array-based generation for `minimumPhpVersion`, `paths`, `allowlists`, `selection`. These are static-shape sections; preserving the dump path keeps quoting and indentation behaviour identical to what users already see. `src/Cli/Command/InitCommand.php` (search: `buildScaffoldDocument`) is the canonical caller.
2. **Rules section by hand** — `src/Cli/Command/InitCommand.php` (search: `renderRulesSection`) iterates `RuleRegistry::all()`, emits `    # {description-or-name}\n`, then dumps a single-key array `[$ruleDefinition->id => $entry]` through `Yaml::dump(..., self::YAML_INLINE_DEPTH, self::YAML_INDENT, Yaml::DUMP_EMPTY_ARRAY_AS_SEQUENCE)` and prefixes every output line with 4 spaces to nest under `rules:`. The dump-per-rule call preserves all the option/threshold formatting logic without re-implementing it; only the framing (comment + indent) is manual.
3. **Concatenate** — `FILE_HEADER . $scaffoldYaml . $rulesYaml` in `execute()`. The scaffold dump ends with a trailing newline so `rules:` begins on a fresh line cleanly.

**Why per-rule dump rather than one large manual builder:** `Yaml::dump` of one rule entry is ~5 lines and stays in lockstep with whatever option shapes the registry adds later (booleans, ints, string lists, nested arrays). A hand-written rule formatter would have to re-implement that switch and would drift as new option types appear. The per-rule dump keeps the formatting authority in Symfony YAML; the wrapper only owns the comment and the indent.

**Caveat:** Most `RuleDefinition` instances under `src/Rules/` leave the `description` field empty (only ~11 of ~130 set it as of 2026-05-25), so the rendered comment falls back to `$ruleDefinition->name` — a short label like `# Cognitive complexity` rather than a sentence explaining when the rule fires. The pattern is sound; the comments only become high-quality once descriptions are populated. Future passes that flesh out descriptions get a free comment-quality upgrade with no init-command changes.

**Verification:** `tests/Console/InitCliTest.php` already parses the emitted YAML with `Yaml::parse` and asserts shape (`testInitWritesDefaultConfigFile` → `assertGeneratedConfigShape` + `assertCognitiveComplexityDefault`). Comments are invisible to the parser, so the existing assertions cover correctness of the split-and-concatenate pipeline; run `vendor/bin/phpunit tests/Console/InitCliTest.php` to confirm no regression after any change to `renderRulesSection` or `buildScaffoldDocument`.

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

`src/Cli/Command/SummaryCommand.php` (search: `summaryFormat($input, $output)`) is the canonical example: format → top limit → config conflict → prompt → registry → analysis → write. `src/Cli/Command/AnalyseCommandSetupBuilder.php` (search: `MissingConfigPrompt::maybeOffer`) and `src/Cli/Dashboard/DashboardCommand.php` (search: `MissingConfigPrompt::maybeOffer`) follow the same order — every locally validated option (`--format`, `--fail-on`, `--mutation-budget`, `--port`, `--scan-timeout`) is checked before the prompt fires. `ReportCommand` validates its own `--output` directory but still fires the prompt before forwarding analyse-level options (`--fail-on`, `--format`, threshold flags) to its subprocess; the remaining gap is recorded in `.goat-flow/learning-loop/footguns/commands.md` under "Side-effect prompts placed before forwarded-option validation mutate the project on bad inputs".

**Stream routing:** Symfony's `QuestionHelper` writes to whichever `OutputInterface` it receives. When the command can emit machine-readable payloads (`json`, `sarif`, `html`, `hotspot`), pass `ConsoleOutputInterface::getErrorOutput()` to prompts and dispatched progress so the primary `$output` stream stays parseable. The same rule applies to deprecation notices, recoverable-error messages, and any chatter from a delegated subprocess.

**Sub-command dispatch:** When using `$application->find('inner')->run(new ArrayInput([...]), $output)` to invoke another console command, the inner command runs in the current process's CWD. If the outer command has a non-CWD project root (`DashboardCommand` with `--project /other/repo`), either pass an explicit `--project-root` option in the `ArrayInput` (requiring the inner command to declare one) or wrap the call in `chdir($projectRoot)` with a `chdir($originalCwd)` in a `finally` block. Document the choice once and apply it everywhere; bare `ArrayInput(['command' => 'inner'])` is unsafe whenever the outer command can address a project root other than CWD.

**Verification:** For any new command that follows this pattern, add a CLI test under `tests/Console/` that:

- Sends a malformed value for each validated option and asserts `Command::INVALID` plus *no* filesystem change.
- Runs with `--no-interaction` to confirm prompts are correctly bypassed for non-interactive shells.
- Runs with a machine-readable format and a piped stdout; asserts the captured payload parses cleanly with no leading prompt or chatter.
- For dispatched sub-commands, sets a non-CWD project root and asserts the inner command's side effect lands in the right directory.

These four assertions catch every PR #3 review finding before they can recur.

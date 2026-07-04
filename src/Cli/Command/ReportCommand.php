<?php

declare(strict_types=1);

namespace GruffPhp\Cli\Command;

use GruffPhp\Engine\Config\ConfigException;
use GruffPhp\Engine\Config\ConfigLoader;
use GruffPhp\Rules\RuleRegistry;
use GruffPhp\Support\PathHelper;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\ConsoleOutputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Process\Process;

/**
 * Backs the `gruff-php report` command - the shareable, saved twin of the on-screen `analyse` view.
 *
 * Reach for this when a user wants a report they can keep or hand off rather than terminal output:
 * an HTML page to open in a browser or a JSON document for another tool, streamed to stdout or
 * written to a file with `--output`. It validates the flags first, then runs the real `analyse`
 * command as a subprocess and captures its rendered output verbatim, forwarding the user's config,
 * filter, diff, baseline, and mutation flags so the saved report matches exactly what `analyse`
 * would have shown live.
 */
final class ReportCommand extends Command
{
    /**
     * Analyse flags that carry a string value, forwarded to the child scan when the user set one.
     *
     * @var list<string>
     */
    private const STRING_OPTIONS = [
        'config',
        'profile',
        'infection-report',
        'infection-bin',
        'infection-config',
        'infection-test-framework-options',
        'mutation-baseline',
        'mutation-budget',
        'history-file',
        'diff-vs',
        'since',
        'changed-ranges',
        'changed-scope',
        'paths-relative-to',
        'min-severity',
        'runtime-mode',
    ];

    /**
     * Analyse on/off flags, forwarded to the child scan for each one the user switched on.
     *
     * @var list<string>
     */
    private const BOOLEAN_OPTIONS = [
        'no-baseline',
        'no-config',
        'no-cache',
        'include-ignored',
        'changed-only',
        'fail-on-new',
        'baseline-include-absent',
        'infection-run',
        'print-runtime',
    ];

    /**
     * Analyse flags the user may repeat, forwarded once per value so lists and filters survive intact.
     *
     * @var list<string>
     */
    private const REPEATED_OPTIONS = [
        'include-pillar',
        'exclude-pillar',
        'include-rule',
        'exclude-rule',
    ];

    /**
     * Registers the `report` command's name, its paths argument, and its large forwarded flag set, whose
     * descriptions Symfony renders into `--help` - everything a user can type after `gruff-php report`.
     *
     * @return void - Nothing is returned; Symfony reads the configured command definition afterward.
     */
    protected function configure(): void
    {
        $this
            ->setName('report')
            ->setDescription('Render a gruff-php report to stdout or a file.')
            ->addArgument('paths', InputArgument::IS_ARRAY | InputArgument::OPTIONAL, 'Files or directories to analyse.')
            ->addOption('format', null, InputOption::VALUE_REQUIRED, 'Report format: html or json.', default: 'html')
            ->addOption('output', null, InputOption::VALUE_REQUIRED, 'Write the report to this file.')
            ->addOption('config', null, InputOption::VALUE_REQUIRED, 'Path to a gruff YAML config file (.yaml or .yml).')
            ->addOption('no-config', null, InputOption::VALUE_NONE, 'Skip auto-applying the default .gruff-php.yaml file for this run.')
            ->addOption('profile', null, InputOption::VALUE_REQUIRED, 'Rule execution profile: default or security.')
            ->addOption('fail-on', null, InputOption::VALUE_REQUIRED, 'Finding severity that fails the scan: advisory, warning, error, or none.', default: 'none')
            ->addOption('fail-on-new', null, InputOption::VALUE_NONE, 'Fail only on findings introduced by the change (requires --baseline or --diff-vs). The report artifact is still written when analysis completes.')
            ->addOption('no-cache', null, InputOption::VALUE_NONE, 'Disable the on-disk result cache for this run (analyse every file fresh).')
            ->addOption('report-editor-link', null, InputOption::VALUE_REQUIRED, 'Editor link style for HTML file:line references: vscode, phpstorm, or none.', default: 'none')
            ->addOption('report-interactive', null, InputOption::VALUE_OPTIONAL, 'Render opt-in interactive HTML finding filters. Accepts true or false.', default: null)
            ->addOption('include-ignored', null, InputOption::VALUE_NONE, 'Scan ignored files by using filesystem traversal instead of Git/default ignores.')
            ->addOption('infection-report', null, InputOption::VALUE_REQUIRED, 'Path to a full Infection JSON report to ingest.')
            ->addOption('infection-run', null, InputOption::VALUE_NONE, 'Run Infection before ingesting the report path supplied by --infection-report.')
            ->addOption('infection-bin', null, InputOption::VALUE_REQUIRED, 'Infection executable for --infection-run.')
            ->addOption('infection-config', null, InputOption::VALUE_REQUIRED, 'Path to infection.json5 for --infection-run.')
            ->addOption('infection-test-framework-options', null, InputOption::VALUE_REQUIRED, 'Options passed to Infection/PHPUnit for --infection-run.')
            ->addOption('mutation-baseline', null, InputOption::VALUE_REQUIRED, 'Path to a baseline Infection JSON report for MSI diff mode.')
            ->addOption('mutation-budget', null, InputOption::VALUE_REQUIRED, 'Maximum escaped/timed-out mutants allowed.')
            ->addOption('diff', null, InputOption::VALUE_OPTIONAL, 'Filter findings to changed lines. Use working-tree, staged, unstaged, or a base ref.', default: null)
            ->addOption('diff-vs', null, InputOption::VALUE_REQUIRED, 'Compare current findings against a base Git ref and report introduced/removed/unchanged findings.')
            ->addOption('changed-only', null, InputOption::VALUE_NONE, 'With --diff-vs, compare only files changed from the base ref.')
            ->addOption('since', null, InputOption::VALUE_REQUIRED, 'Filter findings to files and regions changed since this Git base ref.')
            ->addOption('changed-ranges', null, InputOption::VALUE_REQUIRED, 'Filter findings to explicit line ranges, for example "3-3,8-10".')
            ->addOption('changed-scope', null, InputOption::VALUE_REQUIRED, 'Changed-region scope: symbol, hunk, or file. Use file to keep file-level aggregates and class aggregate span hits in changed-file review workflows.')
            ->addOption('paths-relative-to', null, InputOption::VALUE_REQUIRED, 'Normalize absolute finding paths relative to this directory for reports.')
            ->addOption('min-severity', null, InputOption::VALUE_REQUIRED, 'Display only findings at or above advisory, warning, or error.')
            ->addOption('include-pillar', null, InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY, 'Display only these comma-separated pillars or repeated values.')
            ->addOption('exclude-pillar', null, InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY, 'Hide these comma-separated pillars or repeated values.')
            ->addOption('include-rule', null, InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY, 'Run only these comma-separated rule IDs or repeated values.')
            ->addOption('exclude-rule', null, InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY, 'Skip running these comma-separated rule IDs or repeated values.')
            ->addOption('history-file', null, InputOption::VALUE_REQUIRED, 'Append score trend history to this JSON file.')
            ->addOption(
                'baseline',
                null,
                InputOption::VALUE_OPTIONAL,
                'Suppress findings that match a gruff baseline JSON file. Defaults to "gruff-baseline.json" at the project root when present.',
            )
            ->addOption('no-baseline', null, InputOption::VALUE_NONE, 'Skip auto-applying the default baseline file for this run.')
            ->addOption('baseline-include-absent', null, InputOption::VALUE_NONE, 'With a baseline applied, list resolved (absent) baseline entries in text, markdown, and HTML output.')
            ->addOption('print-runtime', null, InputOption::VALUE_NONE, 'Emit performance instrumentation (wall, peak memory, phase, optional per-rule) as JSON on stderr.')
            ->addOption('runtime-mode', null, InputOption::VALUE_REQUIRED, 'Runtime payload detail: summary (default) or detailed (adds per-rule totals).');
    }

    /**
     * Runs the whole `gruff-php report` invocation: validate the flags, spawn `analyse` to do the real
     * work, then stream its rendered report to the terminal or save it with `--output`. Its error early
     * returns stop with a clear message rather than a broken report; the config-offer passthrough instead forwards init's own exit code.
     *
     * @param InputInterface  $input - Console input carrying report paths, format, and forwarded analyse options.
     * @param OutputInterface $output - Destination for the rendered report, status lines, and forwarded child stderr.
     *
     * @return int - Symfony exit code; it mirrors the analyse subprocess, so a fail-on threshold hit still fails the report.
     */
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $projectRoot = getcwd();

        // Without a readable working directory there is nowhere to anchor the report's paths, so stop here.
        if ($projectRoot === false) {
            $output->writeln('<error>Unable to determine current working directory.</error>');

            return Command::FAILURE;
        }

        $outputPath     = $this->optionalStringOption($input, 'output');
        $resolvedOutput = null;
        // Only when the user asked to save with `--output` do we need a real directory to write into.
        if ($outputPath !== null) {
            $resolvedOutput = PathHelper::resolveAgainst($projectRoot, $outputPath);
            // The parent folder is missing, so fail now rather than after a full scan the file can't hold.
            if (!is_dir(dirname($resolvedOutput))) {
                $output->writeln(sprintf('<error>Report output directory does not exist: %s</error>', dirname($resolvedOutput)));

                return Command::INVALID;
            }
        }

        $ruleFilterError = $this->ruleFilterUsageError($input);
        // A `--include-rule`/`--exclude-rule` named a rule id we don't recognise, so surface the typo up front.
        if ($ruleFilterError !== null) {
            $output->writeln(sprintf('<error>%s</error>', $ruleFilterError));

            return Command::INVALID;
        }

        $profileUsageError = AnalyseCommandOptions::profileUsageErrorFor($this->optionalStringOption($input, 'profile') ?? 'default');
        // A bad `--profile` value like `--profile=fast` is caught before report can prompt, write config, or spawn analyse.
        if ($profileUsageError !== null) {
            $output->writeln(sprintf('<error>%s</error>', $profileUsageError));

            return Command::INVALID;
        }

        $profileIncludeError = $this->profileIncludeUsageError($input);
        // Catching an incoherent `--profile`/`--include-rule` pair here keeps the init prompt from firing (and
        // possibly writing config) for a run the forwarded analyse would refuse anyway.
        if ($profileIncludeError !== null) {
            $output->writeln(sprintf('<error>%s</error>', $profileIncludeError));

            return Command::INVALID;
        }

        // Only `report --format json` counts as machine-readable here: the default html output is a
        // file artifact whose stdout stays prompt-safe (the offer rides stderr), so genuinely
        // interactive html users keep the init offer while json consumers never see it.
        $promptExitCode = MissingConfigPrompt::maybeOffer(
            input:                   $input,
            output:                  $output,
            symfonyApplication:      $this->getApplication(),
            projectRoot:             $projectRoot,
            explicitConfigPath:      $this->optionalStringOption($input, 'config'),
            shouldSkipConfig:        (bool)$input->getOption('no-config'),
            isMachineReadableFormat: ($this->optionalStringOption($input, 'format') ?? 'html') === 'json',
        );
        // A non-null code here means the config-creation offer ran init and it failed, so surface init's exit code instead of continuing to a report.
        if ($promptExitCode !== null) {
            return $promptExitCode;
        }

        $process = new Process($this->analyseCommand($input), $projectRoot);
        $process->setTimeout(null);
        $process->run();
        $this->writeStderr($output, $process->getErrorOutput());
        $report = $process->getOutput();

        // No `--output` was given, so stream the report straight to the terminal and echo the child's exit code.
        if ($resolvedOutput === null) {
            $output->write($report, false, OutputInterface::OUTPUT_RAW);

            return $process->getExitCode() ?? Command::FAILURE;
        }

        $exitCode = $process->getExitCode() ?? Command::FAILURE;

        // Analyse rejected the run or produced nothing usable, so don't write an empty or misleading file.
        if ($exitCode === Command::INVALID || ($report === '' && $exitCode !== Command::SUCCESS)) {
            $output->writeln(sprintf('<error>Analyse exited with code %d; %s was not written.</error>', $exitCode, $outputPath));

            return $exitCode;
        }

        // The scan succeeded but the report file couldn't be written (bad permissions, full disk); say so plainly.
        if (file_put_contents($resolvedOutput, $report) === false) {
            $output->writeln(sprintf('<error>Unable to write report: %s</error>', $resolvedOutput));

            return Command::FAILURE;
        }

        $output->writeln(sprintf('<info>Report written to %s</info>', $resolvedOutput));

        return $exitCode;
    }

    /**
     * Translates the user's report flags into the argv for the child `analyse` run, so the subprocess
     * scans exactly what the user asked for. Assembled once per report invocation.
     *
     * @param InputInterface $input - Report-command input whose options and paths are translated into analyse flags.
     *
     * @return list<string> - full analyse subprocess argv, starting with the PHP binary and `analyse`, with forwarded flags and trailing paths
     */
    private function analyseCommand(InputInterface $input): array
    {
        // The child analyse runs behind pipes where nobody can answer a prompt, so always pass
        // `--no-interaction`; the report command itself already handled the missing-config offer.
        $command   = [PHP_BINARY, $this->gruffBinary(), 'analyse', '--no-interaction', '--format'];
        $command[] = $this->optionalStringOption($input, 'format') ?? 'html';
        $command[] = '--fail-on';
        $command[] = $this->resolveFailOn($input);

        $this->appendStringOptions($command, $input);
        $this->appendReportEditorLinkOption($command, $input);
        $this->appendBaselineOption($command, $input);
        $this->appendBooleanOptions($command, $input);
        $this->appendRepeatedOptions($command, $input);
        $this->appendReportInteractiveOption($command, $input);
        $this->appendDiffOption($command, $input);
        $this->appendPaths($command, $input);

        return $command;
    }

    /**
     * Appends the files or directories the user named after a `--` separator, so a path that starts
     * with a dash is still treated as a path and never mistaken for an option.
     *
     * @param list<string>   $command - Analyse command arguments built so far.
     * @param InputInterface $input - Supplies the variadic paths argument appended after the `--` separator.
     *
     * @return void - Nothing is returned; user paths are appended to `$command` in place.
     */
    private function appendPaths(array &$command, InputInterface $input): void
    {
        /** @var list<string> $paths The command definition declares a variadic paths argument. */
        $paths = $input->getArgument('paths');

        // No paths given means "scan the whole project", so there is nothing to append after the separator.
        if ($paths === []) {
            return;
        }

        $command[] = '--';
        array_push($command, ...$paths);
    }

    /**
     * Passes each string-valued flag the user set (like `--config` or `--since`) through to the child
     * scan, quietly dropping the ones they left unset so no empty flags reach analyse.
     *
     * @param list<string>   $command - Analyse command arguments built so far.
     * @param InputInterface $input - Source of the STRING_OPTIONS values; empty or unset options are skipped.
     *
     * @return void - Nothing is returned; matching flag pairs are appended to `$command` in place.
     */
    private function appendStringOptions(array &$command, InputInterface $input): void
    {
        // Walk each string-valued flag the user might have set and forward only the ones carrying a value.
        foreach (self::STRING_OPTIONS as $option) {
            $optionValue = $this->optionalStringOption($input, $option);

            // The user left this flag unset or blank, so there is nothing to pass on to analyse.
            if ($optionValue === null) {
                continue;
            }

            $command[] = '--' . $option;
            $command[] = $optionValue;
        }
    }

    /**
     * Forwards the user's `--report-editor-link` choice (vscode or phpstorm) so HTML file:line
     * references become clickable, but drops the default `none` since it needs no flag.
     *
     * @param list<string>   $command - Analyse command arguments built so far.
     * @param InputInterface $input - Source of report-editor-link; the default "none" is treated as unset and dropped.
     *
     * @return void - Nothing is returned; the editor-link flag is appended to `$command` when it applies.
     */
    private function appendReportEditorLinkOption(array &$command, InputInterface $input): void
    {
        $reportEditorLink = $this->optionalStringOption($input, 'report-editor-link');
        // Forward the editor-link style only when the user chose one; the default `none` needs no flag.
        if ($reportEditorLink !== null && $reportEditorLink !== 'none') {
            $command[] = '--report-editor-link';
            $command[] = $reportEditorLink;
        }
    }

    /**
     * Forwards `--baseline` when the user asked to suppress known findings, passing along an explicit
     * path when they gave one or the bare flag (which falls back to the default baseline file) when
     * they did not.
     *
     * @param list<string>   $command - Analyse command arguments built so far.
     * @param InputInterface $input - Source of --baseline; the flag is forwarded only when the user passed it.
     *
     * @return void - Nothing is returned; the baseline flag (and any path) is appended to `$command` in place.
     */
    private function appendBaselineOption(array &$command, InputInterface $input): void
    {
        // Only forward `--baseline` when the user actually typed it, since its mere presence changes behaviour.
        if ($input->hasParameterOption('--baseline', true)) {
            $optionValue = $this->optionalStringOption($input, 'baseline');
            $command[]   = '--baseline';

            // A path was given (`--baseline=known.json`), so pass it along; a bare `--baseline` forwards alone.
            if ($optionValue !== null) {
                $command[] = $optionValue;
            }
        }
    }

    /**
     * Passes through the on/off flags the user switched on (like `--no-cache` or `--fail-on-new`),
     * leaving any they didn't set off in the child run.
     *
     * @param list<string>   $command - Analyse command arguments built so far.
     * @param InputInterface $input - Source of the BOOLEAN_OPTIONS flags; only flags resolving to true are forwarded.
     *
     * @return void - Nothing is returned; each enabled flag is appended to `$command` in place.
     */
    private function appendBooleanOptions(array &$command, InputInterface $input): void
    {
        // Run through each on/off flag the report exposes so any the user enabled reaches analyse.
        foreach (self::BOOLEAN_OPTIONS as $option) {
            // Add the flag only when the user turned it on; a flag left off stays off in the child run.
            if ((bool)$input->getOption($option)) {
                $command[] = '--' . $option;
            }
        }
    }

    /**
     * Passes through the filter flags the user may list more than once (like `--include-rule` or
     * `--exclude-pillar`), emitting one flag pair per value so every repeat reaches the child scan.
     *
     * @param list<string>   $command - Analyse command arguments built so far.
     * @param InputInterface $input - Source of the REPEATED_OPTIONS arrays; each non-empty value yields one flag pair.
     *
     * @return void - Nothing is returned; one flag pair per value is appended to `$command` in place.
     */
    private function appendRepeatedOptions(array &$command, InputInterface $input): void
    {
        // Handle each repeatable filter flag (like `--include-rule`) the user may have listed several times.
        foreach (self::REPEATED_OPTIONS as $option) {
            $values = $input->getOption($option);

            // The user never used this flag, so there are no values to forward.
            if (!is_array($values)) {
                continue;
            }

            // Emit one flag pair per value the user supplied, preserving repeats and comma-joined lists.
            foreach ($values as $optionValue) {
                // Skip blank entries so an empty `--include-rule=` can't forward a meaningless flag.
                if (is_string($optionValue) && $optionValue !== '') {
                    $command[] = '--' . $option;
                    $command[] = $optionValue;
                }
            }
        }
    }

    /**
     * Forwards `--report-interactive` when the user opted into (or explicitly out of) the HTML report's
     * client-side finding filters, passing their exact true/false value or the bare flag.
     *
     * @param list<string>   $command - Analyse command arguments built so far.
     * @param InputInterface $input - Source of --report-interactive; a string value is passed as `=value`, else bare.
     *
     * @return void - Nothing is returned; the interactive flag is appended to `$command` when the user opted in.
     */
    private function appendReportInteractiveOption(array &$command, InputInterface $input): void
    {
        // Forward the interactive-report choice only when the user opted in with the flag.
        if ($input->hasParameterOption('--report-interactive', true)) {
            $interactive = $input->getOption('report-interactive');

            // The user passed an explicit `--report-interactive=true|false`, so forward that exact choice.
            if (is_string($interactive) && $interactive !== '') {
                $command[] = '--report-interactive=' . $interactive;
            } else {
                // A bare `--report-interactive` with no value forwards as the flag alone, taking the default.
                $command[] = '--report-interactive';
            }
        }
    }

    /**
     * Forwards `--diff` when the user wants findings limited to changed lines, carrying their mode
     * (working-tree, staged, unstaged, or a base ref) or the bare flag for the default.
     *
     * @param list<string>   $command - Analyse command arguments built so far.
     * @param InputInterface $input - Source of --diff; forwarded only when present, with its mode value when non-empty.
     *
     * @return void - Nothing is returned; the diff flag (and any mode) is appended to `$command` in place.
     */
    private function appendDiffOption(array &$command, InputInterface $input): void
    {
        // Forward `--diff` only when the user asked for it; its presence alone switches on changed-line filtering.
        if ($input->hasParameterOption('--diff', true)) {
            $command[] = '--diff';
            $diff      = $input->getOption('diff');

            // A mode was given (`--diff=staged`), so pass it; a bare `--diff` defaults to the working tree downstream.
            if (is_string($diff) && $diff !== '') {
                $command[] = $diff;
            }
        }
    }

    /**
     * Settles the `--fail-on` threshold for the report run so the same severity gate `analyse` would
     * apply decides this report's pass or fail. An explicit `--fail-on` wins, then the config's
     * `minimumSeverity.report`, then the binary default `none`; the winner is forwarded as an explicit flag.
     *
     * @param InputInterface $input - Console input for the report command.
     *
     * @return string - Resolved threshold forwarded to the child `--fail-on`: advisory, warning, error, or none.
     */
    private function resolveFailOn(InputInterface $input): string
    {
        // Symfony returns the `none` default even when the user never typed `--fail-on`, so detect a real
        // flag via the raw argv; otherwise a config-supplied `minimumSeverity.report` could never win.
        if ($input->hasParameterOption('--fail-on', true)) {
            // Explicit CLI flag is the top of the precedence chain; empty value degrades to `none`.
            return $this->optionalStringOption($input, 'fail-on') ?? 'none';
        }

        $configThreshold = $this->loadConfigFailThreshold($input);
        // No explicit flag, so a `minimumSeverity.report` from the user's config outranks the binary default.
        if ($configThreshold !== null) {
            return $configThreshold;
        }

        // Neither CLI flag nor config supplied a threshold; fall back to the report binary default.
        return 'none';
    }

    /**
     * Best-effort read of the config's `minimumSeverity.report` so a project can set its own report
     * gate. Load errors are swallowed on purpose - the analyse subprocess re-loads the same file and
     * reports any problem itself - so a failure here just defers to the caller's default.
     *
     * @param InputInterface $input - Console input for the report command.
     *
     * @return string|null - Configured report threshold; null when `--no-config` is set, the working directory is unreadable, the config omits it, or loading failed, leaving the caller on its default.
     */
    private function loadConfigFailThreshold(InputInterface $input): ?string
    {
        // `--no-config` opts out of config entirely, so there is no project threshold to contribute.
        if ((bool)$input->getOption('no-config')) {
            return null;
        }

        $projectRoot = getcwd();
        // Without a working directory the config can't be located, so leave the caller on its default.
        if ($projectRoot === false) {
            return null;
        }

        try {
            $config = (new ConfigLoader($projectRoot, ConfigLoader::packageRoot()))
                ->load($this->optionalStringOption($input, 'config'), RuleRegistry::defaults());
        } catch (ConfigException) {
            // Swallow load errors here; the analyse subprocess re-loads the config and reports them itself.
            return null;
        }

        // May be null when the config omits minimumSeverity.report, leaving the default to the caller.
        return $config->failThresholdFor('report')?->value;
    }

    /**
     * Reads a flag as a real string value or "unset", so every caller can treat an omitted, blank, or
     * non-string option the same way instead of guarding each one.
     *
     * @param InputInterface $input - Console input to read the option from.
     * @param string         $name - Option name to read, without the leading dashes.
     *
     * @return string|null - The option's non-empty string value; null when the user omitted the flag or left it blank.
     */
    private function optionalStringOption(InputInterface $input, string $name): ?string
    {
        $optionValue = $input->getOption($name);

        // Normalise array/bool/empty option values to null so callers can treat "unset" uniformly.
        return is_string($optionValue) && $optionValue !== '' ? $optionValue : null;
    }

    /**
     * Rejects `--profile security` paired with an `--include-rule` that names a rule outside that
     * profile, so the mismatch fails as a clear usage error here instead of surfacing later from the
     * spawned analyse. Runs after id validation so every requested id is known to the registry.
     *
     * @param InputInterface $input - Console input for the report command.
     *
     * @return string|null - Usage error for the first out-of-profile include; null when the profile and includes agree, so the run proceeds.
     */
    private function profileIncludeUsageError(InputInterface $input): ?string
    {
        $profile             = $this->optionalStringOption($input, 'profile') ?? 'default';
        $profileScorePillars = AnalyseCommandOptions::scorePillarsForProfile($profile);
        // Profiles that score every pillar have nothing to check; unknown profiles were rejected earlier.
        if ($profileScorePillars === null) {
            return null;
        }

        return AnalyseCommandSetupBuilder::outOfProfileIncludeError(
            RuleRegistry::defaults(),
            $profile,
            $profileScorePillars,
            $this->ruleIdListOption($input, 'include-rule'),
        );
    }

    /**
     * Catches a mistyped `--include-rule`/`--exclude-rule` id before the run does anything, so the user
     * gets a plain usage error instead of a scan that silently ignores the id or dies inside the
     * spawned analyse. Runs ahead of the init prompt and the child process.
     *
     * @param InputInterface $input - Console input for the report command.
     *
     * @return string|null - Usage error naming the first unknown id; null when every id is registered, so the run proceeds.
     */
    private function ruleFilterUsageError(InputInterface $input): ?string
    {
        $registry = RuleRegistry::defaults();

        // Check both the include and exclude rule lists the user supplied for ids the registry doesn't know.
        foreach (['include-rule', 'exclude-rule'] as $optionName) {
            $unknownRuleIds = $registry->unknownRuleIds($this->ruleIdListOption($input, $optionName));
            // The first unrecognised id is almost always a typo, so name it and stop before scanning.
            if ($unknownRuleIds !== []) {
                return sprintf('Unknown rule id "%s" for --%s.', $unknownRuleIds[0], $optionName);
            }
        }

        return null;
    }

    /**
     * Reads a repeatable rule-id option the way the user typed it - trimmed and comma-split - matching
     * how analyse parses the same flags so validation here agrees with what the child run would accept.
     *
     * @param InputInterface $input - Console input for the report command.
     * @param string         $name - Repeatable option name to read.
     *
     * @return list<string> - Unique, non-empty rule ids in the order given; empty when the flag was unused or held only blanks.
     */
    private function ruleIdListOption(InputInterface $input, string $name): array
    {
        $values = $input->getOption($name);

        // The user never used this option, so there are no ids to parse.
        if (!is_array($values)) {
            return [];
        }

        $ruleIds = [];

        // Each value the user typed may itself be a comma-joined list, so unpack them one entry at a time.
        foreach ($values as $optionValue) {
            // Ignore blank or non-string entries so an empty `--include-rule=` contributes nothing.
            if (!is_string($optionValue) || $optionValue === '') {
                continue;
            }

            // Split a single `a,b,c` value into its individual rule ids.
            foreach (explode(',', $optionValue) as $optionPart) {
                $trimmedOptionPart = trim($optionPart);
                // Keep only ids that survive trimming, dropping stray whitespace or empty commas.
                if ($trimmedOptionPart !== '') {
                    $ruleIds[] = $trimmedOptionPart;
                }
            }
        }

        return array_values(array_unique($ruleIds));
    }

    /**
     * Points at the gruff-php executable shipped alongside this class so the report's child scan runs
     * the very same install the user launched, not another copy on their PATH.
     *
     * @return string - Absolute path to this package's `bin/gruff-php`.
     */
    private function gruffBinary(): string
    {
        // Resolve the sibling bin/ wrapper from this class file so the subprocess uses this same install.
        return dirname(__DIR__, 3) . '/bin/gruff-php';
    }

    /**
     * Relays the child analyse process's stderr to the user, preferring the real error channel so
     * diagnostics never pollute the report on stdout. Called once after the subprocess finishes.
     *
     * @param OutputInterface $output - Target stream; a console output's dedicated error channel is used when available.
     * @param string          $stderr - Captured analyse stderr; an empty string forwards nothing, otherwise a trailing newline is ensured.
     *
     * @return void - Nothing is returned; the child's stderr is written straight to the chosen stream.
     */
    private function writeStderr(OutputInterface $output, string $stderr): void
    {
        // A silent child produced no stderr, so forward nothing and don't print a stray blank line.
        if ($stderr === '') {
            return;
        }

        // A full console output has its own error channel, so send diagnostics there and off the report's stdout.
        if ($output instanceof ConsoleOutputInterface) {
            $output->getErrorOutput()->write($stderr, false, OutputInterface::OUTPUT_RAW);

            // Analyse didn't end its stderr with a newline, so close the error channel's line cleanly.
            if (!str_ends_with($stderr, "\n")) {
                $output->getErrorOutput()->writeln('');
            }
            // Already delivered on the dedicated error channel; returning prevents the shared-stream
            // write used for plain (non-ConsoleOutputInterface) outputs from duplicating it onto stdout.
            return;
        }

        $output->write($stderr, false, OutputInterface::OUTPUT_RAW);

        // On a plain stream stderr just landed on stdout beside the report, so add the missing newline to split them.
        if (!str_ends_with($stderr, "\n")) {
            $output->writeln('');
        }
    }
}

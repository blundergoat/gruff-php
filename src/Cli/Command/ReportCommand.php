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
 * Runs `gruff-php report` by capturing a child `analyse` process's output and forwarding supported scan options.
 *
 * The format defaults to HTML; --format selects it, and --output saves the captured report.
 * Completed scans retain the child's exit status unless saving the report fails.
 */
final class ReportCommand extends Command
{
    /**
     * Analyse flags that carry a string value, forwarded to the child scan when the user set one.
     *
     * @var list<string> - Option names with values validated by analyse.
     */
    private const STRING_OPTIONS = [
        'config',
        'deep-scan-budget',
        'profile',
        'infection-report',
        'infection-bin',
        'infection-config',
        'infection-test-framework-options',
        'mutation-baseline',
        'mutation-budget',
        'history-file',
        'diff-base',
        'diff-vs',
        'since',
        'changed-ranges',
        'changed-scope',
        'paths-relative-to',
        'min-severity',
        'min-confidence',
        'runtime-mode',
    ];

    /**
     * Analyse on/off flags, forwarded to the child scan for each one the user switched on.
     *
     * @var list<string> - Option names forwarded when their values cast to true.
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
     * @var list<string> - Option names forwarded once per non-empty string value.
     */
    private const REPEATED_OPTIONS = [
        'include-pillar',
        'exclude-pillar',
        'include-rule',
        'exclude-rule',
        'show-rule',
        'hide-rule',
        'show-pillar',
        'hide-pillar',
    ];

    /**
     * Registers the report command's paths and report-specific options, whose descriptions Symfony shows in help output.
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
            ->addOption('deep-scan-budget', null, InputOption::VALUE_REQUIRED, 'Bound structural analysis as <lines>:<bytes>, or disable it with off.')
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
            ->addOption('diff-base', null, InputOption::VALUE_REQUIRED, 'Base ref for a branch comparison. Supersedes --diff-vs.')
            ->addOption('changed-only', null, InputOption::VALUE_NONE, 'With --diff-vs, compare only files changed from the base ref.')
            ->addOption('since', null, InputOption::VALUE_REQUIRED, 'Filter findings to files and regions changed since this Git base ref.')
            ->addOption('changed-ranges', null, InputOption::VALUE_REQUIRED, 'Filter findings to explicit line ranges, for example "3-3,8-10".')
            ->addOption('changed-scope', null, InputOption::VALUE_REQUIRED, 'Changed-region scope: symbol, hunk, or file. Use file to keep file-level aggregates and class aggregate span hits in changed-file review workflows.')
            ->addOption('paths-relative-to', null, InputOption::VALUE_REQUIRED, 'Normalize absolute finding paths relative to this directory for reports.')
            ->addOption('min-severity', null, InputOption::VALUE_REQUIRED, 'Display only findings at or above advisory, warning, or error.')
            ->addOption('min-confidence', null, InputOption::VALUE_REQUIRED, 'Lowest confidence that reaches the exit gate: low, medium, or high.')
            ->addOption('include-pillar', null, InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY, 'Display only these comma-separated pillars or repeated values.')
            ->addOption('exclude-pillar', null, InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY, 'Hide these comma-separated pillars or repeated values.')
            ->addOption('include-rule', null, InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY, 'Run only these comma-separated rule IDs or repeated values.')
            ->addOption('exclude-rule', null, InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY, 'Skip running these comma-separated rule IDs or repeated values.')
            ->addOption('show-rule', null, InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY, 'Display only the named rule IDs.')
            ->addOption('hide-rule', null, InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY, 'Hide the named rule IDs.')
            ->addOption('show-pillar', null, InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY, 'Display only the named pillars.')
            ->addOption('hide-pillar', null, InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY, 'Hide the named pillars.')
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
     * Validates report options, runs analyse, then writes its rendered report to the terminal or the
     * --output file. Returns local setup failures or the forwarded init and analyse exit status.
     *
     * @param InputInterface  $input  - Console input carrying report paths, format, and forwarded analyse options.
     * @param OutputInterface $output - Destination for the rendered report, status lines, and forwarded child stderr.
     *
     * @return int - Child exit code after analysis, or a local usage, init, or report-write failure code.
     */
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $launchDirectory = getcwd();

        // Without a readable working directory there is nowhere to anchor the report's paths, so stop here.
        if ($launchDirectory === false) {
            $output->writeln('<error>Unable to determine current working directory.</error>');

            return Command::FAILURE;
        }

        /** @var list<string> $paths - Operands parsed by the array argument. */
        $paths       = (array)$input->getArgument('paths');
        $projectRoot = AnalyseCommandSetupBuilder::projectRootFromTargets($launchDirectory, $paths);
        // Refuse targets with no shared scan root before choosing a project's config.
        if ($projectRoot === null) {
            $output->writeln('<error>Scan targets do not share a filesystem root.</error>');

            return Command::INVALID;
        }

        $budget         = $input->getOption('deep-scan-budget');
        $budgetOverride = AnalyseCommandOptions::parseDeepScanBudgetOverride(is_string($budget) ? $budget : null);
        // Validate the budget before offering to create a config or starting analysis.
        if (is_string($budgetOverride)) {
            $output->writeln('<error>' . $budgetOverride . '</error>');

            return Command::INVALID;
        }

        $outputPath     = $this->optionalStringOption($input, 'output');
        $resolvedOutput = null;

        // Interpret --output relative to the calling shell even when the report scans another project.
        if ($outputPath !== null) {
            $resolvedOutput = PathHelper::resolveAgainst($launchDirectory, $outputPath);
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

        // HTML runs retain the init offer; JSON runs suppress it to protect parsed output.
        // The offer uses STDERR when the supplied output exposes a separate error stream.

        $promptExitCode = MissingConfigPrompt::maybeOffer(
            input:                   $input,
            output:                  $output,
            symfonyApplication:      $this->getApplication(),
            projectRoot:             $projectRoot,
            explicitConfigPath:      $this->optionalStringOption($input, 'config'),
            shouldSkipConfig:        (bool)$input->getOption('no-config'),
            isMachineReadableFormat: ($this->optionalStringOption($input, 'format') ?? 'html') === 'json',
        );
        // A failed init stops the report invocation with init's exit code.
        if ($promptExitCode !== null) {
            return $promptExitCode;
        }

        $process = new Process($this->analyseCommand($input, $projectRoot), $launchDirectory);
        $process->setTimeout(null);
        $process->run();
        $this->writeStderr($output, $process->getErrorOutput());
        $report = $process->getOutput();


        // Without --output, print the report so a caller can pipe it to another program.
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

        // A write failure overrides the scan's exit status because no report artifact was saved.
        if (file_put_contents($resolvedOutput, $report) === false) {
            $output->writeln(sprintf('<error>Unable to write report: %s</error>', $resolvedOutput));

            return Command::FAILURE;
        }

        $output->writeln(sprintf('<info>Report written to %s</info>', $resolvedOutput));

        return $exitCode;
    }

    /**
     * Translates the user's report flags into the argv for the child analyse run, so the subprocess scans exactly what the user asked for.
     *
     * @param InputInterface $input       - Report-command input whose options and paths are translated into analyse flags.
     * @param string         $projectRoot - Target root used to resolve the report exit threshold.
     *
     * @return list<string> - Child argv: PHP binary, package executable, analyse, flags, then any paths.
     */
    private function analyseCommand(InputInterface $input, string $projectRoot): array
    {
        // The child analyse runs behind pipes where nobody can answer a prompt, so always pass
        // --no-interaction; the report command itself already handled the missing-config offer.
        $command   = [PHP_BINARY, $this->gruffBinary(), 'analyse', '--no-interaction', '--format'];
        $command[] = $this->optionalStringOption($input, 'format') ?? 'html';
        $command[] = '--fail-on';
        $command[] = $this->resolveFailOn($input, $projectRoot);

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
     * @param InputInterface $input   - Supplies the variadic paths argument appended after the `--` separator.
     *
     * @return void - Nothing is returned; user paths are appended to `$command` in place.
     */
    private function appendPaths(array &$command, InputInterface $input): void
    {
        /** @var list<string> $paths - The command definition declares a variadic paths argument. */
        $paths = $input->getArgument('paths');

        // With no explicit paths, leave the child's configured discovery scope unchanged.
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
     * @param InputInterface $input   - Source of the STRING_OPTIONS values; empty or unset options are skipped.
     *
     * @return void - Nothing is returned; matching flag pairs are appended to `$command` in place.
     */
    private function appendStringOptions(array &$command, InputInterface $input): void
    {

        // Forward explicitly supplied options so analyse uses the same scope and display choices as report.
        foreach (self::STRING_OPTIONS as $option) {
            $optionValue = $input->getOption($option);


            // An omitted option lets analyse read the default from the target project.
            if (!is_string($optionValue)) {
                continue;
            }

            $command[] = '--' . $option;
            $command[] = $optionValue;
        }
    }

    /**
     * Forwards any non-default report editor-link value and omits the none value from the child arguments.
     *
     * @param list<string>   $command - Analyse command arguments built so far.
     * @param InputInterface $input   - Source of report-editor-link; the default "none" is treated as unset and dropped.
     *
     * @return void - Nothing is returned; the editor-link flag is appended to `$command` when it applies.
     */
    private function appendReportEditorLinkOption(array &$command, InputInterface $input): void
    {
        $reportEditorLink = $this->optionalStringOption($input, 'report-editor-link');

        // A caller who enables editor links gets the selected editor in the HTML report.
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
     * @param InputInterface $input   - Source of --baseline; the flag is forwarded only when the user passed it.
     *
     * @return void - Nothing is returned; the baseline flag (and any path) is appended to `$command` in place.
     */
    private function appendBaselineOption(array &$command, InputInterface $input): void
    {
        // Only forward `--baseline` when the user actually typed it, since its mere presence changes behaviour.
        if ($input->hasParameterOption('--baseline', true)) {
            $optionValue = $this->optionalStringOption($input, 'baseline');
            $command[]   = '--baseline';


            // Keep a value attached to its flag only when Symfony recorded one for the caller.
            if ($optionValue !== null) {
                $command[] = $optionValue;
            }
        }
    }

    /**
     * Passes through the on/off flags the user switched on (like `--no-cache` or `--fail-on-new`), leaving any they didn't set off in the child run.
     *
     * @param list<string>   $command - Analyse command arguments built so far.
     * @param InputInterface $input   - Source of the BOOLEAN_OPTIONS flags; only flags resolving to true are forwarded.
     *
     * @return void - Nothing is returned; each enabled flag is appended to `$command` in place.
     */
    private function appendBooleanOptions(array &$command, InputInterface $input): void
    {

        // Forward only the switches the caller requested, keeping analyse's normal defaults otherwise.
        foreach (self::BOOLEAN_OPTIONS as $option) {

            // A disabled switch contributes nothing to the child command.
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
     * @param InputInterface $input   - Source of the REPEATED_OPTIONS arrays; each non-empty value yields one flag pair.
     *
     * @return void - Nothing is returned; one flag pair per value is appended to `$command` in place.
     */
    private function appendRepeatedOptions(array &$command, InputInterface $input): void
    {

        // Repeated flags retain the caller's list of targets, execution rules, or display filters.
        foreach (self::REPEATED_OPTIONS as $option) {
            $values = $input->getOption($option);


            // An absent repeatable flag gives the child no additional selection.
            if (!is_array($values)) {
                continue;
            }

            // Forward each supplied value in its own argument so the child reads the same list.
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
     * @param InputInterface $input   - Source of --report-interactive; non-empty strings use =value, otherwise the flag is bare.
     *
     * @return void - Appends the explicitly supplied flag, including an explicit false value, to $command.
     */
    private function appendReportInteractiveOption(array &$command, InputInterface $input): void
    {

        // An explicitly supplied report mode needs to reach the renderer, even for the bare switch.
        if ($input->hasParameterOption('--report-interactive', true)) {
            $interactive = $input->getOption('report-interactive');


            // A supplied true or false value selects the report behavior through the child command.
            if (is_string($interactive) && $interactive !== '') {
                $command[] = '--report-interactive=' . $interactive;
            } else {

                $command[] = '--report-interactive';
            }
        }
    }

    /**
     * Forwards `--diff` when the user wants findings limited to changed lines, carrying their mode
     * (working-tree, staged, unstaged, or a base ref) or the bare flag for the default.
     *
     * @param list<string>   $command - Analyse command arguments built so far.
     * @param InputInterface $input   - Source of --diff; forwarded only when present, with its mode value when non-empty.
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
     * Resolves the report exit threshold and forwards it explicitly to analyse.
     * An explicit --fail-on wins, then config failOn.report, then the default none.
     *
     * @param InputInterface $input       - Console input for the report command.
     * @param string         $projectRoot - Target project's configuration root.
     *
     * @return string - Threshold string forwarded to the child's --fail-on option.
     */
    private function resolveFailOn(InputInterface $input, string $projectRoot): string
    {
        // Symfony returns the `none` default even when the user never typed `--fail-on`, so detect a real
        // flag via the raw argv; otherwise a config-supplied `failOn.report` could never win.
        if ($input->hasParameterOption('--fail-on', true)) {
            // Forward the raw explicit flag so an empty `--fail-on=` fails validation instead of silently turning the gate off.
            $rawFailOn = $input->getOption('fail-on');

            return is_string($rawFailOn) ? $rawFailOn : '';
        }

        return $this->loadConfigFailThreshold($input, $projectRoot) ?? 'none';
    }

    /**
     * Reads failOn.report, leaving the caller's default in place when no threshold is available.
     * ConfigException is left for the analyse subprocess to report when it loads the same config.
     *
     * @param InputInterface $input       - Console input for the report command.
     * @param string         $projectRoot - Target root used by the child analysis.
     *
     * @return string|null - Configured threshold, or null when no threshold can be loaded.
     */
    private function loadConfigFailThreshold(InputInterface $input, string $projectRoot): ?string
    {
        // `--no-config` opts out of config entirely, so there is no project threshold to contribute.
        if ((bool)$input->getOption('no-config')) {
            return null;
        }

        try {
            $config = (new ConfigLoader($projectRoot, shouldResolveFromLaunchDir: true))
                ->load($this->optionalStringOption($input, 'config'), RuleRegistry::defaults());
        } catch (ConfigException) {
            // An unreadable or malformed config is reported by the child analyse command, which owns the diagnostic.
            return null;
        }

        // May be null when the config omits failOn.report, leaving the default to the caller.
        return $config->failThresholdFor('report')?->value;
    }

    /**
     * Returns an option's non-empty string value, including a configured default.
     * Empty strings and non-string values are treated as unset.
     *
     * @param InputInterface $input - Console input to read the option from.
     * @param string         $name  - Option name to read, without the leading dashes.
     *
     * @return string|null - Non-empty string value, or null for an empty string or non-string value.
     */
    private function optionalStringOption(InputInterface $input, string $name): ?string
    {
        $optionValue = $input->getOption($name);


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
     * Rejects unknown --include-rule or --exclude-rule IDs before prompting for config or starting analyse.
     *
     * @param InputInterface $input - Console input for the report command.
     *
     * @return string|null - Usage error naming the first unknown id; null when every id is registered, so the run proceeds.
     */
    private function ruleFilterUsageError(InputInterface $input): ?string
    {
        $registry = RuleRegistry::defaults();


        // Reject unknown execution rules before offering to create any config.
        foreach (['include-rule', 'exclude-rule'] as $optionName) {
            $unknownRuleIds = $registry->unknownRuleIds($this->ruleIdListOption($input, $optionName));

            // Include the flag name in the error so a caller can find the misspelling in a script.
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
     * @param string         $name  - Repeatable option name to read.
     *
     * @return list<string> - Unique, non-empty rule ids in the order given; empty when the flag was unused or held only blanks.
     */
    private function ruleIdListOption(InputInterface $input, string $name): array
    {
        $values = $input->getOption($name);


        // No values means the caller did not restrict the execution rules with this flag.
        if (!is_array($values)) {
            return [];
        }

        $ruleIds = [];


        // Read each occurrence because callers can combine repeated flags and comma-separated IDs.
        foreach ($values as $optionValue) {

            // Empty entries contribute no selection.
            if (!is_string($optionValue) || $optionValue === '') {
                continue;
            }


            // Strip spaces around comma-separated IDs before validating the catalogue.
            foreach (explode(',', $optionValue) as $optionPart) {
                $trimmedOptionPart = trim($optionPart);
                // Keep only ids that survive trimming, and store a retired id as its replacement so it still filters today's findings.
                if ($trimmedOptionPart !== '') {
                    $ruleIds[] = RuleRegistry::canonicalRuleId($trimmedOptionPart);
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

        return dirname(__DIR__, 3) . '/bin/gruff-php';
    }

    /**
     * Relays captured analyse diagnostics after the child process finishes.
     * Uses a dedicated error stream when available; otherwise diagnostics share the supplied report output.
     *
     * @param OutputInterface $output - Destination whose dedicated error stream is preferred when available.
     * @param string          $stderr - Captured diagnostics; empty emits nothing, otherwise a missing trailing newline is added.
     *
     * @return void - Writes diagnostics and any required newline to the selected output.
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


            // Finish the diagnostic line so the shell prompt does not run into the error text.
            if (!str_ends_with($stderr, "\n")) {
                $output->getErrorOutput()->writeln('');
            }


            return;
        }

        $output->write($stderr, false, OutputInterface::OUTPUT_RAW);


        // When only a combined stream is available, still leave the shell prompt on its own line.
        if (!str_ends_with($stderr, "\n")) {
            $output->writeln('');
        }
    }
}

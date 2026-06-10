<?php

declare(strict_types=1);

namespace GruffPhp\Command;

use GruffPhp\Config\ConfigException;
use GruffPhp\Config\ConfigLoader;
use GruffPhp\Rule\RuleRegistry;
use GruffPhp\Support\PathHelper;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\ConsoleOutputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Process\Process;

/**
 * Implements the gruff-php report CLI command for saved analysis output.
 */
final class ReportCommand extends Command
{
    /**
     * Analyse options forwarded when their value is a non-empty string.
     *
     * @var list<string>
     */
    private const STRING_OPTIONS = [
        'config',
        'infection-report',
        'mutation-baseline',
        'mutation-budget',
        'history-file',
        'diff-vs',
        'paths-relative-to',
        'min-severity',
    ];

    /**
     * Analyse flags forwarded when present and true.
     *
     * @var list<string>
     */
    private const BOOLEAN_OPTIONS = [
        'no-baseline',
        'no-config',
        'include-ignored',
        'changed-only',
    ];

    /**
     * Analyse options forwarded once for each non-empty value.
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
     * Configure the report command arguments and options.
     *
     * @return void
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
            ->addOption('fail-on', null, InputOption::VALUE_REQUIRED, 'Finding severity that fails the scan: advisory, warning, error, or none.', default: 'none')
            ->addOption('report-editor-link', null, InputOption::VALUE_REQUIRED, 'Editor link style for HTML file:line references: vscode, phpstorm, or none.', default: 'none')
            ->addOption('report-interactive', null, InputOption::VALUE_OPTIONAL, 'Render opt-in interactive HTML finding filters. Accepts true or false.', default: null)
            ->addOption('include-ignored', null, InputOption::VALUE_NONE, 'Scan ignored files by using filesystem traversal instead of Git/default ignores.')
            ->addOption('infection-report', null, InputOption::VALUE_REQUIRED, 'Path to a full Infection JSON report to ingest.')
            ->addOption('mutation-baseline', null, InputOption::VALUE_REQUIRED, 'Path to a baseline Infection JSON report for MSI diff mode.')
            ->addOption('mutation-budget', null, InputOption::VALUE_REQUIRED, 'Maximum escaped/timed-out mutants allowed.')
            ->addOption('diff', null, InputOption::VALUE_OPTIONAL, 'Filter findings to changed lines. Use working-tree, staged, unstaged, or a base ref.', default: null)
            ->addOption('diff-vs', null, InputOption::VALUE_REQUIRED, 'Compare current findings against a base Git ref and report introduced/removed/unchanged findings.')
            ->addOption('changed-only', null, InputOption::VALUE_NONE, 'With --diff-vs, compare only files changed from the base ref.')
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
            ->addOption('no-baseline', null, InputOption::VALUE_NONE, 'Skip auto-applying the default baseline file for this run.');
    }

    /**
     * Run analysis through the analyse command and emit or write the report.
     *
     * @param InputInterface  $input - Console input carrying report paths, format, and forwarded analyse options.
     * @param OutputInterface $output - Destination for the rendered report, status lines, and forwarded child stderr.
     *
     * @return int - Symfony command exit code.
     */
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $projectRoot = getcwd();

        if ($projectRoot === false) {
            $output->writeln('<error>Unable to determine current working directory.</error>');

            return Command::FAILURE;
        }

        $outputPath     = $this->optionalStringOption($input, 'output');
        $resolvedOutput = null;
        if ($outputPath !== null) {
            $resolvedOutput = PathHelper::resolveAgainst($projectRoot, $outputPath);
            if (!is_dir(dirname($resolvedOutput))) {
                $output->writeln(sprintf('<error>Report output directory does not exist: %s</error>', dirname($resolvedOutput)));

                return Command::INVALID;
            }
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
        if ($promptExitCode !== null) {
            return $promptExitCode;
        }

        $process = new Process($this->analyseCommand($input), $projectRoot);
        $process->setTimeout(null);
        $process->run();
        $this->writeStderr($output, $process->getErrorOutput());
        $report = $process->getOutput();

        if ($resolvedOutput === null) {
            $output->write($report, false, OutputInterface::OUTPUT_RAW);

            return $process->getExitCode() ?? Command::FAILURE;
        }

        $exitCode = $process->getExitCode() ?? Command::FAILURE;

        if ($exitCode === Command::INVALID || ($report === '' && $exitCode !== Command::SUCCESS)) {
            $output->writeln(sprintf('<error>Analyse exited with code %d; %s was not written.</error>', $exitCode, $outputPath));

            return $exitCode;
        }

        if (file_put_contents($resolvedOutput, $report) === false) {
            $output->writeln(sprintf('<error>Unable to write report: %s</error>', $resolvedOutput));

            return Command::FAILURE;
        }

        $output->writeln(sprintf('<info>Report written to %s</info>', $resolvedOutput));

        return $exitCode;
    }

    /**
     * Build the analyse command argv used by report generation.
     *
     * @param InputInterface $input - Report-command input whose options and paths are translated into analyse flags.
     *
     * @return list<string> - full analyse subprocess argv, starting with the PHP binary and `analyse`, with forwarded flags and trailing paths
     */
    private function analyseCommand(InputInterface $input): array
    {
        // The child analyse runs behind pipes where nobody can answer a prompt, so always pass
        // --no-interaction; the report command itself already handled the missing-config offer.
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
     * Append user paths after an option separator so dash-prefixed paths stay positional.
     *
     * @param list<string>   $command - Analyse command arguments built so far.
     * @param InputInterface $input - Supplies the variadic paths argument appended after the `--` separator.
     *
     * @return void
     */
    private function appendPaths(array &$command, InputInterface $input): void
    {
        /** @var list<string> $paths The command definition declares a variadic paths argument. */
        $paths = $input->getArgument('paths');

        if ($paths === []) {
            return;
        }

        $command[] = '--';
        array_push($command, ...$paths);
    }

    /**
     * Forward all configured string-valued options to the analyse command.
     *
     * @param list<string>   $command - Analyse command arguments built so far.
     * @param InputInterface $input - Source of the STRING_OPTIONS values; empty or unset options are skipped.
     *
     * @return void
     */
    private function appendStringOptions(array &$command, InputInterface $input): void
    {
        foreach (self::STRING_OPTIONS as $option) {
            $optionValue = $this->optionalStringOption($input, $option);

            if ($optionValue === null) {
                continue;
            }

            $command[] = '--' . $option;
            $command[] = $optionValue;
        }
    }

    /**
     * Forward the report editor-link option unless it uses the default disabled value.
     *
     * @param list<string>   $command - Analyse command arguments built so far.
     * @param InputInterface $input - Source of report-editor-link; the default "none" is treated as unset and dropped.
     *
     * @return void
     */
    private function appendReportEditorLinkOption(array &$command, InputInterface $input): void
    {
        $reportEditorLink = $this->optionalStringOption($input, 'report-editor-link');
        if ($reportEditorLink !== null && $reportEditorLink !== 'none') {
            $command[] = '--report-editor-link';
            $command[] = $reportEditorLink;
        }
    }

    /**
     * Forward the optional baseline flag with its optional path.
     *
     * @param list<string>   $command - Analyse command arguments built so far.
     * @param InputInterface $input - Source of --baseline; the flag is forwarded only when the user passed it.
     *
     * @return void
     */
    private function appendBaselineOption(array &$command, InputInterface $input): void
    {
        if ($input->hasParameterOption('--baseline', true)) {
            $optionValue = $this->optionalStringOption($input, 'baseline');
            $command[]   = '--baseline';

            if ($optionValue !== null) {
                $command[] = $optionValue;
            }
        }
    }

    /**
     * Forward true boolean flags to the analyse command.
     *
     * @param list<string>   $command - Analyse command arguments built so far.
     * @param InputInterface $input - Source of the BOOLEAN_OPTIONS flags; only flags resolving to true are forwarded.
     *
     * @return void
     */
    private function appendBooleanOptions(array &$command, InputInterface $input): void
    {
        foreach (self::BOOLEAN_OPTIONS as $option) {
            if ((bool)$input->getOption($option)) {
                $command[] = '--' . $option;
            }
        }
    }

    /**
     * Forward repeated filter options to the analyse command.
     *
     * @param list<string>   $command - Analyse command arguments built so far.
     * @param InputInterface $input - Source of the REPEATED_OPTIONS arrays; each non-empty value yields one flag pair.
     *
     * @return void
     */
    private function appendRepeatedOptions(array &$command, InputInterface $input): void
    {
        foreach (self::REPEATED_OPTIONS as $option) {
            $values = $input->getOption($option);

            if (!is_array($values)) {
                continue;
            }

            foreach ($values as $optionValue) {
                if (is_string($optionValue) && $optionValue !== '') {
                    $command[] = '--' . $option;
                    $command[] = $optionValue;
                }
            }
        }
    }

    /**
     * Forward the optional interactive report flag.
     *
     * @param list<string>   $command - Analyse command arguments built so far.
     * @param InputInterface $input - Source of --report-interactive; a string value is passed as `=value`, else bare.
     *
     * @return void
     */
    private function appendReportInteractiveOption(array &$command, InputInterface $input): void
    {
        if ($input->hasParameterOption('--report-interactive', true)) {
            $interactive = $input->getOption('report-interactive');

            if (is_string($interactive) && $interactive !== '') {
                $command[] = '--report-interactive=' . $interactive;
            } else {
                $command[] = '--report-interactive';
            }
        }
    }

    /**
     * Forward the optional diff flag with its optional mode value.
     *
     * @param list<string>   $command - Analyse command arguments built so far.
     * @param InputInterface $input - Source of --diff; forwarded only when present, with its mode value when non-empty.
     *
     * @return void
     */
    private function appendDiffOption(array &$command, InputInterface $input): void
    {
        if ($input->hasParameterOption('--diff', true)) {
            $command[] = '--diff';
            $diff      = $input->getOption('diff');

            if (is_string($diff) && $diff !== '') {
                $command[] = $diff;
            }
        }
    }

    /**
     * Apply ADR-015 precedence for the report command's --fail-on value.
     *
     * Explicit CLI flag > config.minimumSeverity.report > binary default `none`.
     * The resolved value is forwarded to the analyse subprocess as an explicit
     * --fail-on, so the subprocess uses it directly rather than re-applying
     * analyse's own precedence chain (which has a different binary default).
     *
     * @param InputInterface $input - Console input for the report command.
     *
     * @return string - Resolved threshold suitable for `--fail-on`.
     */
    private function resolveFailOn(InputInterface $input): string
    {
        // Symfony's $input->getOption('fail-on') returns the option's default value
        // ('none') even when the user did not pass --fail-on at all, so we have to
        // detect "explicit" via the raw parameter list. Otherwise the config-supplied
        // minimumSeverity.report value can never win, defeating the M11 wiring.
        if ($input->hasParameterOption('--fail-on', true)) {
            // Explicit CLI flag is the top of the precedence chain; empty value degrades to `none`.
            return $this->optionalStringOption($input, 'fail-on') ?? 'none';
        }

        $configThreshold = $this->loadConfigFailThreshold($input);
        if ($configThreshold !== null) {
            // No CLI flag, so config.minimumSeverity.report takes precedence over the binary default.
            return $configThreshold;
        }

        // Neither CLI flag nor config supplied a threshold; fall back to the report binary default.
        return 'none';
    }

    /**
     * Load the project config (best-effort) and read `minimumSeverity.report`.
     *
     * Config errors are swallowed because the analyse subprocess re-loads the
     * same config and will surface any failures through its usual diagnostic
     * path. Returning null lets the caller fall back to the binary default.
     *
     * @param InputInterface $input - Console input for the report command.
     *
     * @return string|null - Resolved threshold string, or null when unavailable.
     */
    private function loadConfigFailThreshold(InputInterface $input): ?string
    {
        if ((bool)$input->getOption('no-config')) {
            // --no-config opts out of config entirely, so there is no threshold to contribute.
            return null;
        }

        $projectRoot = getcwd();
        if ($projectRoot === false) {
            // Without a working directory the config cannot be located; let the caller use the default.
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
     * Read a non-empty string option from console input.
     *
     * @param InputInterface $input - Console input to read the option from.
     * @param string         $name - Option name to read, without the leading dashes.
     *
     * @return string|null - Option value, or null when omitted/empty.
     */
    private function optionalStringOption(InputInterface $input, string $name): ?string
    {
        $optionValue = $input->getOption($name);

        // Normalise array/bool/empty option values to null so callers can treat "unset" uniformly.
        return is_string($optionValue) && $optionValue !== '' ? $optionValue : null;
    }

    /**
     * Return the package-local gruff-php executable path.
     *
     * @return string - Absolute gruff-php binary path.
     */
    private function gruffBinary(): string
    {
        // Resolve the sibling bin/ wrapper from this class file so the subprocess uses this same install.
        return dirname(__DIR__, 2) . '/bin/gruff-php';
    }

    /**
     * Forward child process stderr to the most appropriate output stream.
     *
     * @param OutputInterface $output - Target stream; the dedicated error channel is used when one is available.
     * @param string          $stderr - Captured analyse stderr; an empty string is a no-op, otherwise a newline is ensured.
     *
     * @return void
     */
    private function writeStderr(OutputInterface $output, string $stderr): void
    {
        if ($stderr === '') {
            // Nothing to forward; avoid emitting a stray blank line for silent runs.
            return;
        }

        if ($output instanceof ConsoleOutputInterface) {
            $output->getErrorOutput()->write($stderr, false, OutputInterface::OUTPUT_RAW);

            if (!str_ends_with($stderr, "\n")) {
                $output->getErrorOutput()->writeln('');
            }
            // Already delivered on the dedicated error channel; returning prevents the shared-stream
            // write used for plain (non-ConsoleOutputInterface) outputs from duplicating it onto stdout.
            return;
        }

        $output->write($stderr, false, OutputInterface::OUTPUT_RAW);

        if (!str_ends_with($stderr, "\n")) {
            $output->writeln('');
        }
    }
}

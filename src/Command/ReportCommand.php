<?php

declare(strict_types=1);

namespace GruffPhp\Command;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\ConsoleOutputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Process\Process;

/**
 * Implements the gruff report CLI command for saved analysis output.
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
     * @return void No return value.
     */
    protected function configure(): void
    {
        $this
            ->setName('report')
            ->setDescription('Render a gruff report to stdout or a file.')
            ->addArgument('paths', InputArgument::IS_ARRAY | InputArgument::OPTIONAL, 'Files or directories to analyse.')
            ->addOption('format', null, InputOption::VALUE_REQUIRED, 'Report format: html or json.', 'html')
            ->addOption('output', null, InputOption::VALUE_REQUIRED, 'Write the report to this file.')
            ->addOption('config', null, InputOption::VALUE_REQUIRED, 'Path to a gruff YAML config file (.yaml or .yml).')
            ->addOption('no-config', null, InputOption::VALUE_NONE, 'Skip auto-applying the default .gruff.yaml file for this run.')
            ->addOption('fail-on', null, InputOption::VALUE_REQUIRED, 'Finding severity that fails the scan: advisory, warning, error, or none.', 'none')
            ->addOption('report-editor-link', null, InputOption::VALUE_REQUIRED, 'Editor link style for HTML file:line references: vscode, phpstorm, or none.', 'none')
            ->addOption('report-interactive', null, InputOption::VALUE_OPTIONAL, 'Render opt-in interactive HTML finding filters. Accepts true or false.', null)
            ->addOption('include-ignored', null, InputOption::VALUE_NONE, 'Include files under default ignored directories.')
            ->addOption('infection-report', null, InputOption::VALUE_REQUIRED, 'Path to a full Infection JSON report to ingest.')
            ->addOption('mutation-baseline', null, InputOption::VALUE_REQUIRED, 'Path to a baseline Infection JSON report for MSI diff mode.')
            ->addOption('mutation-budget', null, InputOption::VALUE_REQUIRED, 'Maximum escaped/timed-out mutants allowed.')
            ->addOption('diff', null, InputOption::VALUE_OPTIONAL, 'Filter findings to changed lines. Use working-tree, staged, unstaged, or a base ref.', null)
            ->addOption('diff-vs', null, InputOption::VALUE_REQUIRED, 'Compare current findings against a base Git ref and report introduced/removed/unchanged findings.')
            ->addOption('changed-only', null, InputOption::VALUE_NONE, 'With --diff-vs, compare only files changed from the base ref.')
            ->addOption('paths-relative-to', null, InputOption::VALUE_REQUIRED, 'Normalize absolute finding paths relative to this directory for reports.')
            ->addOption('min-severity', null, InputOption::VALUE_REQUIRED, 'Display only findings at or above advisory, warning, or error.')
            ->addOption('include-pillar', null, InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY, 'Display only these comma-separated pillars or repeated values.')
            ->addOption('exclude-pillar', null, InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY, 'Hide these comma-separated pillars or repeated values.')
            ->addOption('include-rule', null, InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY, 'Display only these comma-separated rule IDs or repeated values.')
            ->addOption('exclude-rule', null, InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY, 'Hide these comma-separated rule IDs or repeated values.')
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
     * @return int Symfony command exit code.
     */
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $projectRoot = getcwd();

        if ($projectRoot === false) {
            $output->writeln('<error>Unable to determine current working directory.</error>');

            return Command::FAILURE;
        }

        $process = new Process($this->analyseCommand($input), $projectRoot);
        $process->setTimeout(null);
        $process->run();
        $this->writeStderr($output, $process->getErrorOutput());
        $report     = $process->getOutput();
        $outputPath = $this->optionalStringOption($input, 'output');

        if ($outputPath === null) {
            $output->write($report, false, OutputInterface::OUTPUT_RAW);

            return $process->getExitCode() ?? Command::FAILURE;
        }

        $path      = $this->absolutePath($outputPath, $projectRoot);
        $directory = dirname($path);

        if (!is_dir($directory)) {
            $output->writeln(sprintf('<error>Report output directory does not exist: %s</error>', $directory));

            return Command::INVALID;
        }

        if (file_put_contents($path, $report) === false) {
            $output->writeln(sprintf('<error>Unable to write report: %s</error>', $path));

            return Command::FAILURE;
        }

        $output->writeln(sprintf('<info>Report written to %s</info>', $path));

        return $process->getExitCode() ?? Command::FAILURE;
    }

    /**
     * @return list<string>
     */
    private function analyseCommand(InputInterface $input): array
    {
        /** @var list<string> $paths The command definition declares a variadic paths argument. */
        $paths     = $input->getArgument('paths');
        $command   = [PHP_BINARY, $this->gruffBinary(), 'analyse', ...$paths, '--format'];
        $command[] = $this->optionalStringOption($input, 'format') ?? 'html';
        $command[] = '--fail-on';
        $command[] = $this->optionalStringOption($input, 'fail-on') ?? 'none';

        $this->appendStringOptions($command, $input);
        $this->appendReportEditorLinkOption($command, $input);
        $this->appendBaselineOption($command, $input);
        $this->appendBooleanOptions($command, $input);
        $this->appendRepeatedOptions($command, $input);
        $this->appendReportInteractiveOption($command, $input);
        $this->appendDiffOption($command, $input);

        return $command;
    }

    /**
     * Forward all configured string-valued options to the analyse command.
     *
     * @param list<string> $command Analyse command arguments built so far.
     *
     * @return void No return value.
     */
    private function appendStringOptions(array &$command, InputInterface $input): void
    {
        foreach (self::STRING_OPTIONS as $option) {
            $value = $this->optionalStringOption($input, $option);

            if ($value === null) {
                continue;
            }

            $command[] = '--' . $option;
            $command[] = $value;
        }
    }

    /**
     * Forward the report editor-link option unless it uses the default disabled value.
     *
     * @param list<string> $command Analyse command arguments built so far.
     *
     * @return void No return value.
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
     * @param list<string> $command Analyse command arguments built so far.
     *
     * @return void No return value.
     */
    private function appendBaselineOption(array &$command, InputInterface $input): void
    {
        if ($input->hasParameterOption('--baseline', true)) {
            $value     = $this->optionalStringOption($input, 'baseline');
            $command[] = '--baseline';

            if ($value !== null) {
                $command[] = $value;
            }
        }
    }

    /**
     * Forward true boolean flags to the analyse command.
     *
     * @param list<string> $command Analyse command arguments built so far.
     *
     * @return void No return value.
     */
    private function appendBooleanOptions(array &$command, InputInterface $input): void
    {
        foreach (self::BOOLEAN_OPTIONS as $option) {
            if ((bool) $input->getOption($option)) {
                $command[] = '--' . $option;
            }
        }
    }

    /**
     * Forward repeated filter options to the analyse command.
     *
     * @param list<string> $command Analyse command arguments built so far.
     *
     * @return void No return value.
     */
    private function appendRepeatedOptions(array &$command, InputInterface $input): void
    {
        foreach (self::REPEATED_OPTIONS as $option) {
            $values = $input->getOption($option);

            if (!is_array($values)) {
                continue;
            }

            foreach ($values as $value) {
                if (is_string($value) && $value !== '') {
                    $command[] = '--' . $option;
                    $command[] = $value;
                }
            }
        }
    }

    /**
     * Forward the optional interactive report flag.
     *
     * @param list<string> $command Analyse command arguments built so far.
     *
     * @return void No return value.
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
     * @param list<string> $command Analyse command arguments built so far.
     *
     * @return void No return value.
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
     * Read a non-empty string option from console input.
     *
     * @return string|null Option value, or null when omitted/empty.
     */
    private function optionalStringOption(InputInterface $input, string $name): ?string
    {
        $value = $input->getOption($name);

        return is_string($value) && $value !== '' ? $value : null;
    }

    /**
     * Return the package-local gruff executable path.
     *
     * @return string Absolute gruff binary path.
     */
    private function gruffBinary(): string
    {
        return dirname(__DIR__, 2) . '/bin/gruff';
    }

    /**
     * Resolve a path relative to the project root when needed.
     *
     * @return string Absolute path.
     */
    private function absolutePath(string $path, string $projectRoot): string
    {
        if (str_starts_with($path, '/')) {
            return $path;
        }

        return $projectRoot . '/' . $path;
    }

    /**
     * Forward child process stderr to the most appropriate output stream.
     *
     * @return void No return value.
     */
    private function writeStderr(OutputInterface $output, string $stderr): void
    {
        if ($stderr === '') {
            return;
        }

        if ($output instanceof ConsoleOutputInterface) {
            $output->getErrorOutput()->write($stderr, false, OutputInterface::OUTPUT_RAW);

            if (!str_ends_with($stderr, "\n")) {
                $output->getErrorOutput()->writeln('');
            }

            return;
        }

        $output->write($stderr, false, OutputInterface::OUTPUT_RAW);

        if (!str_ends_with($stderr, "\n")) {
            $output->writeln('');
        }
    }
}

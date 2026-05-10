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

final class ReportCommand extends Command
{
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
        $report = $process->getOutput();
        $outputPath = $this->optionalStringOption($input, 'output');

        if ($outputPath === null) {
            $output->write($report, false, OutputInterface::OUTPUT_RAW);

            return $process->getExitCode() ?? Command::FAILURE;
        }

        $path = $this->absolutePath($outputPath, $projectRoot);
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
        /** @var list<string> $paths */
        $paths = $input->getArgument('paths');
        $command = [PHP_BINARY, $this->gruffBinary(), 'analyse', ...$paths, '--format'];
        $command[] = $this->optionalStringOption($input, 'format') ?? 'html';
        $command[] = '--fail-on';
        $command[] = $this->optionalStringOption($input, 'fail-on') ?? 'none';

        foreach (['config', 'infection-report', 'mutation-baseline', 'mutation-budget', 'history-file', 'diff-vs', 'paths-relative-to', 'min-severity'] as $option) {
            $value = $this->optionalStringOption($input, $option);

            if ($value === null) {
                continue;
            }

            $command[] = '--' . $option;
            $command[] = $value;
        }

        $reportEditorLink = $this->optionalStringOption($input, 'report-editor-link');
        if ($reportEditorLink !== null && $reportEditorLink !== 'none') {
            $command[] = '--report-editor-link';
            $command[] = $reportEditorLink;
        }

        if ($input->hasParameterOption('--baseline', true)) {
            $value = $this->optionalStringOption($input, 'baseline');
            $command[] = '--baseline';

            if ($value !== null) {
                $command[] = $value;
            }
        }

        if ((bool) $input->getOption('no-baseline')) {
            $command[] = '--no-baseline';
        }

        if ((bool) $input->getOption('no-config')) {
            $command[] = '--no-config';
        }

        if ((bool) $input->getOption('include-ignored')) {
            $command[] = '--include-ignored';
        }

        if ((bool) $input->getOption('changed-only')) {
            $command[] = '--changed-only';
        }

        foreach (['include-pillar', 'exclude-pillar', 'include-rule', 'exclude-rule'] as $option) {
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

        if ($input->hasParameterOption('--report-interactive', true)) {
            $interactive = $input->getOption('report-interactive');

            if (is_string($interactive) && $interactive !== '') {
                $command[] = '--report-interactive=' . $interactive;
            } else {
                $command[] = '--report-interactive';
            }
        }

        if ($input->hasParameterOption('--diff', true)) {
            $command[] = '--diff';
            $diff = $input->getOption('diff');

            if (is_string($diff) && $diff !== '') {
                $command[] = $diff;
            }
        }

        return $command;
    }

    private function optionalStringOption(InputInterface $input, string $name): ?string
    {
        $value = $input->getOption($name);

        return is_string($value) && $value !== '' ? $value : null;
    }

    private function gruffBinary(): string
    {
        return dirname(__DIR__, 2) . '/bin/gruff';
    }

    private function absolutePath(string $path, string $projectRoot): string
    {
        if (str_starts_with($path, '/')) {
            return $path;
        }

        return $projectRoot . '/' . $path;
    }

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

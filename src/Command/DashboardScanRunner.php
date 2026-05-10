<?php

declare(strict_types=1);

namespace GruffPhp\Command;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Process\Exception\ProcessTimedOutException;
use Symfony\Component\Process\Process;

final readonly class DashboardScanRunner
{
    public function __construct(
        private string $gruffBinary,
        private DashboardStateFactory $stateFactory,
        private DashboardPageRenderer $renderer,
    ) {
    }

    /**
     * @param array<string, string> $query
     */
    public function scanHtml(DashboardRequestContext $context, array $query): string
    {
        $state = $this->stateFactory->state($context->input, $context->projectRoot, $query);
        $renderer = $this->renderer;
        $commandBuilder = new DashboardScanCommandBuilder($this->gruffBinary);
        $scanRoot = $this->stateFactory->resolveProjectRoot($state['project'], $context->launchRoot);

        if ($scanRoot === null) {
            return $renderer->errorHtml(
                'Project root is not an existing directory.',
                sprintf('Project: %s', $state['project']),
                Command::INVALID,
                0,
            );
        }

        $paths = $commandBuilder->parsePaths($state['paths']);
        $editedUnitTestFiles = [];

        if ($state['mutation'] === 'run') {
            $editedUnitTestFiles = $commandBuilder->editedUnitTestFiles($scanRoot);

            if ($editedUnitTestFiles === []) {
                return $renderer->errorHtml(
                    'No edited unit test files found.',
                    'Dashboard mutation analysis checks only PHPUnit unit test files changed relative to HEAD under tests/. Newly created untracked unit test files are included when the project is a git repository. Use scripts/mutation-test-full.sh for a full unit-suite mutation run.',
                    Command::SUCCESS,
                    0,
                );
            }
        }

        $command = $commandBuilder->analyseCommand($paths, $state, $scanRoot, $editedUnitTestFiles);
        $startedAt = microtime(true);
        $process = new Process($command, $scanRoot);
        $process->setTimeout($state['mutation'] === 'run' ? null : $context->scanTimeout);
        $stderr = '';
        $exitCode = Command::SUCCESS;

        try {
            $process->run();
            $stderr = $process->getErrorOutput();
            $exitCode = $process->getExitCode() ?? Command::FAILURE;
        } catch (ProcessTimedOutException $exception) {
            $stderr = $exception->getMessage();
            $exitCode = Command::FAILURE;
        }

        $durationMs = (int) round((microtime(true) - $startedAt) * 1000);
        $html = $process->getOutput();

        if ($html === '') {
            return $renderer->errorHtml('The scan did not produce HTML output.', $stderr === '' ? 'No stderr output.' : $stderr, $exitCode, $durationMs);
        }

        $html = $renderer->injectMutationButtons($html, $state);

        return $renderer->injectDashboardMetadata(html: $html, projectRoot: $scanRoot, command: $command, exitCode: $exitCode, durationMs: $durationMs);
    }
}

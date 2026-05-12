<?php

declare(strict_types=1);

namespace GruffPhp\Command;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Process\Exception\ProcessTimedOutException;
use Symfony\Component\Process\Process;

/**
 * Runs dashboard scans and converts scan output into HTML.
 */
final readonly class DashboardScanRunner
{
    /**
     * Capture collaborators used to execute dashboard scans and render results.
     */
    public function __construct(
        private string $gruffBinary,
        private DashboardStateFactory $stateFactory,
        private DashboardPageRenderer $renderer,
    ) {
    }

    /**
     * @param array<string, string> $query
     * @return string Dashboard HTML for either scan results or an error panel.
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
        $command = $commandBuilder->analyseCommand($paths, $state);
        $startedAt = microtime(true);
        $process = new Process($command, $scanRoot);
        $process->setTimeout($context->scanTimeout);
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

        return $renderer->injectDashboardMetadata(html: $html, projectRoot: $scanRoot, command: $command, exitCode: $exitCode, durationMs: $durationMs);
    }
}

<?php

declare(strict_types=1);

namespace GruffPhp\Command;

use GruffPhp\Config\ConfigLoader;

final readonly class DashboardScanCommandBuilder
{
    /**
     * Build dashboard-triggered analyse commands for the given gruff binary.
     */
    public function __construct(private string $gruffBinary)
    {
    }

    /**
     * @return list<string>
     */
    public function parsePaths(string $paths): array
    {
        $parts = preg_split('/\s+/', trim($paths));

        if ($parts === false || $parts === ['']) {
            return ['.'];
        }

        $paths = array_values(array_filter(
            $parts,
            static fn (string $path): bool => $path !== '' && !str_starts_with($path, '-'),
        ));

        return $paths === [] ? ['.'] : $paths;
    }

    /**
     * @param list<string> $paths
     * @param array<string, string> $state
     * @phpstan-param array{project: string, paths: string, scanScope: string, failOn: string, config: string, baseline: string, noBaseline: string, noConfig: string, includeIgnored: string, reportInteractive: string} $state
     * @return list<string>
     */
    public function analyseCommand(array $paths, array $state): array
    {
        $command = [PHP_BINARY, $this->gruffBinary, 'analyse', '--format', 'html', '--fail-on', $state['failOn']];

        if ($state['noConfig'] === '1') {
            $command[] = '--no-config';
        } elseif ($state['config'] !== '' && $state['config'] !== ConfigLoader::DEFAULT_CONFIG_FILE) {
            $command[] = '--config';
            $command[] = $state['config'];
        }

        if ($state['noBaseline'] === '1') {
            $command[] = '--no-baseline';
        } elseif ($state['baseline'] !== '') {
            $command[] = '--baseline';
            $command[] = $state['baseline'];
        }

        if ($state['includeIgnored'] === '1') {
            $command[] = '--include-ignored';
        }

        if ($state['reportInteractive'] === '1') {
            $command[] = '--report-interactive';
        }

        if ($state['scanScope'] === 'diff') {
            $command[] = '--diff';
        }

        $command[] = '--';
        array_push($command, ...$paths);

        return $command;
    }
}

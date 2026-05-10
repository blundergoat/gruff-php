<?php

declare(strict_types=1);

namespace GruffPhp\Command;

final readonly class DashboardScanCommandBuilder
{
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

        return array_values(array_filter($parts, static fn (string $path): bool => $path !== ''));
    }

    /**
     * @param list<string> $paths
     * @param array<string, string> $state
     * @phpstan-param array{project: string, paths: string, failOn: string, config: string, baseline: string, noBaseline: string, noConfig: string, includeIgnored: string} $state
     * @return list<string>
     */
    public function analyseCommand(array $paths, array $state): array
    {
        $command = [PHP_BINARY, $this->gruffBinary, 'analyse', ...$paths, '--format', 'html', '--fail-on', $state['failOn']];

        if ($state['noConfig'] === '1') {
            $command[] = '--no-config';
        } elseif ($state['config'] !== '') {
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

        return $command;
    }
}

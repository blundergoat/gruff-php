<?php

declare(strict_types=1);

namespace GruffPhp\Command;

use GruffPhp\Config\ConfigLoader;

/**
 * Builds command arguments for dashboard-triggered scans.
 */
final readonly class DashboardScanCommandBuilder
{
    /**
     * Build dashboard-triggered analyse commands for the given gruff-php binary.
     *
     * @param string $gruffBinary Absolute gruff-php binary path used in dashboard scans.
     */
    public function __construct(private string $gruffBinary)
    {
    }

    /**
     * Parse a dashboard paths string into command arguments.
     *
     * @param string $paths Space-separated paths from the dashboard form, with double quotes for paths containing spaces.
     * @return list<string>
     */
    public function parsePaths(string $paths): array
    {
        if (trim($paths) === '') {
            return ['.'];
        }

        $parsedPaths = [];
        preg_match_all('/"((?:\\\\.|[^"\\\\])*)"|(\S+)/', $paths, $matches, PREG_SET_ORDER);

        foreach ($matches as $match) {
            $quotedPath = $match[1] ?? '';
            $path       = $quotedPath !== '' ? stripcslashes($quotedPath) : ($match[2] ?? '');
            if ($path !== '') {
                $parsedPaths[] = $path;
            }
        }

        return $parsedPaths === [] ? ['.'] : $parsedPaths;
    }

    /**
     * @param         list<string>                                                                                                                                                                                        $paths
     * @param         array<string, string>                                                                                                                                                                               $state
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

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
     * @param string $gruffBinary - Absolute gruff-php binary path used in dashboard scans.
     */
    public function __construct(private string $gruffBinary)
    {
    }

    /**
     * Parse a dashboard paths string into command arguments.
     *
     * @param string $paths - Space-separated paths from the dashboard form, with double quotes for paths containing spaces.
     *
     * @return list<string> - parsed path operands in form order; defaults to ['.'] when input is blank or all-empty
     */
    public function parsePaths(string $paths): array
    {
        if (trim($paths) === '') {
            // Blank input means the dashboard form left the path box empty, so scan the project root.
            return ['.'];
        }

        $parsedPaths = [];
        preg_match_all('/"((?:\\\\.|[^"\\\\])*)"|(\S+)/', $paths, $matches, PREG_SET_ORDER);

        foreach ($matches as $match) {
            $quotedPath = $match[1] ?? '';
            $path       = $quotedPath !== '' ? $this->unescapeQuotedPath($quotedPath) : ($match[2] ?? '');
            if ($path !== '') {
                $parsedPaths[] = $path;
            }
        }

        // Tokens that were all empty collapse to a root scan rather than an argument-less analyse call.
        return $parsedPaths === [] ? ['.'] : $parsedPaths;
    }

    /**
     * Decode only the quote and backslash escapes emitted by the dashboard path tokenizer.
     *
     * @param string $quotedPath - Raw inner text of a double-quoted token, still carrying \" and \\ escapes.
     *
     * @return string - decoded path with the wrapper \" and \\ escapes resolved; inner content otherwise untouched
     */
    private function unescapeQuotedPath(string $quotedPath): string
    {
        $unescapedPath = preg_replace('/\\\\(["\\\\])/', '$1', $quotedPath);

        // preg_replace returns null only on engine error; fall back to the input so a path is always produced.
        return is_string($unescapedPath) ? $unescapedPath : $quotedPath;
    }

    /**
     * @param list<string>           $paths - Source paths selected in the dashboard form; appended after `--`.
     * @param array<string, string>  $state - Sanitised dashboard form state used to build analyse flags.
     *
     * @phpstan-param array{project: string, paths: string, scanScope: string, failOn: string, config: string, baseline: string, noBaseline: string,
     *                noConfig: string, includeIgnored: string, reportInteractive: string} $state
     *
     * @return list<string> - full argv for the analyse run: PHP binary, gruff binary, flags, then path operands after the -- separator
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

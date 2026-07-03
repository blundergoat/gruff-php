<?php

declare(strict_types=1);

namespace GruffPhp\Cli\Dashboard;

use GruffPhp\Engine\Config\ConfigLoader;

/**
 * Builds command arguments for dashboard-triggered scans.
 */
final readonly class DashboardScanCommandBuilder
{
    /**
     * Build dashboard-triggered analyse commands for the given gruff-php binary.
     *
      * User flow: Supports dashboard requests, refreshes, and browser-visible state.
      *
     * @param string $gruffBinary - Absolute gruff-php binary path used in dashboard scans.
     */
    public function __construct(private string $gruffBinary)
    {
    }

    /**
     * Parse a dashboard paths string into command arguments.
     *
      * User flow: Supports dashboard requests, refreshes, and browser-visible state.
      *
     * @param string $paths - Space-separated paths from the dashboard form, with double quotes for paths containing spaces.
     *
     * @return list<string> - parsed path operands in form order; defaults to ['.'] when input is blank or all-empty
     */
    public function parsePaths(string $paths): array
    {
        // User view: choose the dashboard view branch for this case.
        // User view: an empty value becomes a clear dashboard view fallback.
        if (trim($paths) === '') {
            return ['.'];
        }

        $parsedPaths = [];
        preg_match_all('/"((?:\\\\.|[^"\\\\])*)"|(\S+)/', $paths, $matches, PREG_SET_ORDER);

        // User view: add each item that can appear in dashboard view.
        foreach ($matches as $match) {
            // User view: missing data becomes a safe dashboard view default.
            $quotedPath = $match[1] ?? '';
            // User view: missing data becomes a safe dashboard view default.
            // User view: an empty value becomes a clear dashboard view fallback.
            $path       = $quotedPath !== '' ? $this->unescapeQuotedPath($quotedPath) : ($match[2] ?? '');
            // User view: choose the dashboard view branch for this case.
            // User view: an empty value becomes a clear dashboard view fallback.
            if ($path !== '') {
                $parsedPaths[] = $path;
            }
        }

        // User view: an empty value becomes a clear dashboard view fallback.
        return $parsedPaths === [] ? ['.'] : $parsedPaths;
    }

    /**
     * Decode only the quote and backslash escapes emitted by the dashboard path tokenizer.
     *
      * User flow: Supports dashboard requests, refreshes, and browser-visible state.
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
      * User flow: Supports dashboard requests, refreshes, and browser-visible state.
      *
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

        // User view: choose the dashboard view branch for this case.
        if ($state['noConfig'] === '1') {
            $command[] = '--no-config';
        }
        // User view: an empty value becomes a clear dashboard view fallback.
        // User view: choose the next dashboard view branch for this case.
        elseif ($state['config'] !== '' && $state['config'] !== ConfigLoader::DEFAULT_CONFIG_FILE) {
            $command[] = '--config';
            $command[] = $state['config'];
        }

        // User view: choose the dashboard view branch for this case.
        if ($state['noBaseline'] === '1') {
            $command[] = '--no-baseline';
        }
        // User view: an empty value becomes a clear dashboard view fallback.
        // User view: choose the next dashboard view branch for this case.
        elseif ($state['baseline'] !== '') {
            $command[] = '--baseline';
            $command[] = $state['baseline'];
        }

        // User view: choose the dashboard view branch for this case.
        if ($state['includeIgnored'] === '1') {
            $command[] = '--include-ignored';
        }

        // User view: choose the dashboard view branch for this case.
        if ($state['reportInteractive'] === '1') {
            $command[] = '--report-interactive';
        }

        // User view: choose the dashboard view branch for this case.
        if ($state['scanScope'] === 'diff') {
            $command[] = '--diff';
        }

        $command[] = '--';
        array_push($command, ...$paths);

        return $command;
    }
}

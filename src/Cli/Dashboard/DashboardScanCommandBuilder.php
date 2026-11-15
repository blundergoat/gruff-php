<?php

declare(strict_types=1);

namespace GruffPhp\Cli\Dashboard;

use GruffPhp\Engine\Config\ConfigLoader;

/**
 * Turns a dashboard scan form into the exact `gruff-php analyse` command to run.
 *
 * When a user clicks "scan" in the dashboard, their chosen paths and toggles arrive as form fields;
 * this translates them into a safe argv (PHP binary, gruff binary, flags, then path operands after
 * `--`) so a dashboard-triggered scan runs exactly the analyse the CLI would.
 */
final readonly class DashboardScanCommandBuilder
{
    /**
     * Binds the builder to the gruff-php binary that dashboard scans will invoke.
     *
     * @param string $gruffBinary - Absolute gruff-php binary path used in dashboard scans.
     */
    public function __construct(private string $gruffBinary)
    {
    }

    /**
     * Splits the dashboard's free-text paths field into individual path operands.
     *
     * @param string $paths - Space-separated paths from the dashboard form, with double quotes for paths containing spaces.
     *
     * @return list<string> - parsed path operands in form order; defaults to ['.'] when input is blank or all-empty
     */
    public function parsePaths(string $paths): array
    {
        // Blank field means "scan here", so default to the project the user is looking at.
        if (trim($paths) === '') {
            return ['.'];
        }

        $parsedPaths = [];
        // Tokenise into quoted paths (group 1, for names with spaces) or bare paths (group 2).
        preg_match_all('/"((?:\\\\.|[^"\\\\])*)"|(\S+)/', $paths, $matches, PREG_SET_ORDER);


        foreach ($matches as $match) {
            $quotedPath = $match[1] ?? '';
            $path       = $quotedPath !== '' ? $this->unescapeQuotedPath($quotedPath) : ($match[2] ?? '');

            if ($path !== '') {
                $parsedPaths[] = $path;
            }
        }

        // If it all parsed away to nothing (e.g. just empty quotes), still fall back to "scan here".
        return $parsedPaths === [] ? ['.'] : $parsedPaths;
    }

    /**
     * Decodes escaped double quotes and backslashes inside a quoted path, leaving other backslashes intact.
     *
     * @param string $quotedPath - Raw inner text of a double-quoted token, still carrying \" and \\ escapes.
     *
     * @return string - Path with escaped double quotes and backslashes decoded; unchanged if replacement fails.
     */
    private function unescapeQuotedPath(string $quotedPath): string
    {
        // Drop the backslash from each \" or \\ escape; a backslash before any other character stays.
        $unescapedPath = preg_replace('/\\\\(["\\\\])/', '$1', $quotedPath);

        // preg_replace returns null only on engine error; fall back to the input so a path is always produced.
        return is_string($unescapedPath) ? $unescapedPath : $quotedPath;
    }

    /**
     * Builds analyse argv for an HTML report, passing path operands after the -- separator.
     *
     * @param list<string>          $paths - Source paths selected in the dashboard form; appended after the -- separator.
     * @param array<string, string> $state - Dashboard form values used to select flags and option arguments.
     *
     * @phpstan-param array{project: string, paths: string, scanScope: string, failOn: string, config: string, baseline: string, noBaseline: string,
     *                noConfig: string, deepScanBudget: string, includeIgnored: string, reportInteractive: string} $state - Dashboard form fields.
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

        if ($state['deepScanBudget'] !== '') {
            $command[] = '--deep-scan-budget';
            $command[] = $state['deepScanBudget'];
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

        // Everything after the -- separator is a path operand, so user paths can never be mistaken for flags.
        $command[] = '--';
        array_push($command, ...$paths);

        return $command;
    }
}

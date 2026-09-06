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

        // Walk each token the form supplied, unwrapping quoted paths back into plain filesystem paths.
        foreach ($matches as $match) {
            $quotedPath = $match[1] ?? '';
            $path       = $quotedPath !== '' ? $this->unescapeQuotedPath($quotedPath) : ($match[2] ?? '');
            // Drop empty tokens so a stray space can't smuggle a phantom path into the scan.
            if ($path !== '') {
                $parsedPaths[] = $path;
            }
        }

        // If it all parsed away to nothing (e.g. just empty quotes), still fall back to "scan here".
        return $parsedPaths === [] ? ['.'] : $parsedPaths;
    }

    /**
     * Undoes only the \" and \\ escapes the dashboard tokenizer added, leaving the real path untouched.
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
     * Assembles the full analyse argv from the user's sanitised dashboard form choices.
     *
     * @param list<string>          $paths - Source paths selected in the dashboard form; appended after `--`.
     * @param array<string, string> $state - Sanitised dashboard form state used to build analyse flags.
     *
     * @phpstan-param array{project: string, paths: string, scanScope: string, failOn: string, config: string, baseline: string, noBaseline: string,
     *                noConfig: string, deepScanBudget: string, includeIgnored: string, reportInteractive: string} $state
     *
     * @return list<string> - full argv for the analyse run: PHP binary, gruff binary, flags, then path operands after the -- separator
     */
    public function analyseCommand(array $paths, array $state): array
    {
        $command = [PHP_BINARY, $this->gruffBinary, 'analyse', '--format', 'html', '--fail-on', $state['failOn']];

        // Config choice: honour the user's "no config" toggle first, else pass any explicit non-default config file.
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

        // Baseline choice: skip the baseline entirely, or point the scan at the baseline file the user named.
        if ($state['noBaseline'] === '1') {
            $command[] = '--no-baseline';
        } elseif ($state['baseline'] !== '') {
            $command[] = '--baseline';
            $command[] = $state['baseline'];
        }

        // The user ticked "include ignored files", so widen the scan past the usual ignore rules.
        if ($state['includeIgnored'] === '1') {
            $command[] = '--include-ignored';
        }

        // The user asked for the interactive HTML report instead of the plain static one.
        if ($state['reportInteractive'] === '1') {
            $command[] = '--report-interactive';
        }

        // Scope set to "diff": scan only what changed rather than the whole project.
        if ($state['scanScope'] === 'diff') {
            $command[] = '--diff';
        }

        // Everything after `--` is a path operand, so user paths can never be mistaken for flags.
        $command[] = '--';
        array_push($command, ...$paths);

        return $command;
    }
}

<?php

declare(strict_types=1);

namespace GruffPhp\Command;

use GruffPhp\Analysis\RunDiagnostic;
use GruffPhp\Parser\PhpFileParser;
use GruffPhp\Source\SourceDiscovery;
use GruffPhp\Source\SourceDiscoveryResult;

/**
 * Discovers and parses analysis source files for CLI execution.
 */
final readonly class AnalysisSourceLoader
{
    /**
     * @param string       $projectRoot - Root used for source discovery and parsing.
     * @param list<string> $paths - Project-relative paths requested by the CLI.
     * @param bool         $shouldIncludeIgnored - Whether files matching default ignore patterns are included.
     * @param list<string> $ignoredPathPatterns - Configured path patterns to skip unless ignored files are included.
     *
     * @return AnalysisSourceSet - discovered files, their parsed units, and missing-path plus parse-error diagnostics
     */
    public function load(
        string $projectRoot,
        array  $paths,
        bool   $shouldIncludeIgnored,
        array  $ignoredPathPatterns,
    ): AnalysisSourceSet {
        $discoveryResult = (new SourceDiscovery($projectRoot))->discover($paths, $shouldIncludeIgnored, $ignoredPathPatterns);
        $phpFileParser   = new PhpFileParser();
        $diagnostics     = $this->missingPathDiagnostics($discoveryResult);
        $analysisUnits   = [];

        foreach ($discoveryResult->files as $file) {
            $unit            = $phpFileParser->parse($file);
            $analysisUnits[] = $unit;

            foreach ($unit->diagnostics as $diagnostic) {
                $diagnostics[] = new RunDiagnostic(
                    type:     'parse-error',
                    message:  $diagnostic->message,
                    filePath: $file->displayPath,
                    line:     $diagnostic->line,
                );
            }
        }

        // Hand the caller the fully parsed corpus plus any missing-path and parse-error diagnostics.
        return new AnalysisSourceSet($discoveryResult, $analysisUnits, $diagnostics);
    }

    /**
     * Discover sources without parsing them. The caller drives parsing one
     * file at a time so each unit's AST can be released immediately after
     * analysis, keeping peak memory close to one unit's worth.
     *
     * @param string       $projectRoot - Root used for source discovery.
     * @param list<string> $paths - Project-relative paths requested by the CLI.
     * @param bool         $shouldIncludeIgnored - Whether files matching default ignore patterns are included.
     * @param list<string> $ignoredPathPatterns - Configured path patterns to skip unless ignored files are included.
     *
     * @return array{discovery: SourceDiscoveryResult, diagnostics: list<RunDiagnostic>} - unparsed discovery result paired with missing-path
     *                          diagnostics; the caller parses each file itself
     */
    public function discover(
        string $projectRoot,
        array  $paths,
        bool   $shouldIncludeIgnored,
        array  $ignoredPathPatterns,
    ): array {
        $discoveryResult = (new SourceDiscovery($projectRoot))->discover($paths, $shouldIncludeIgnored, $ignoredPathPatterns);

        // Return discovery results unparsed; the streaming caller parses each file in turn so ASTs stay short-lived.
        return [
            'discovery'   => $discoveryResult,
            'diagnostics' => $this->missingPathDiagnostics($discoveryResult),
        ];
    }

    /**
     * Build the diagnostics list for paths that disappeared between argument
     * parsing and discovery.
     *
     * @param SourceDiscoveryResult $sourceDiscoveryResult - Discovery output; each missing path yields one diagnostic.
     *
     * @return list<RunDiagnostic> - one missing-path diagnostic per vanished input path, in discovery order; empty when every path resolved
     */
    private function missingPathDiagnostics(SourceDiscoveryResult $sourceDiscoveryResult): array
    {
        $diagnostics = [];
        foreach ($sourceDiscoveryResult->missingPaths as $missingPath) {
            $diagnostics[] = new RunDiagnostic(
                type:    'missing-path',
                message: 'Input path does not exist.',
                path:    $missingPath,
            );
        }

        // One diagnostic per requested path that vanished before discovery; empty when every path resolved.
        return $diagnostics;
    }
}

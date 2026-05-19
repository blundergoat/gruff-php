<?php

declare(strict_types=1);

namespace GruffPhp\Command;

use GruffPhp\Analysis\RunDiagnostic;
use GruffPhp\Parser\PhpFileParser;
use GruffPhp\Source\SourceDiscovery;

/**
 * Discovers and parses analysis source files for CLI execution.
 */
final readonly class AnalysisSourceLoader
{
    /**
     * @param string       $projectRoot          Root used for source discovery and parsing.
     * @param list<string> $paths                Project-relative paths requested by the CLI.
     * @param bool         $shouldIncludeIgnored Whether files matching default ignore patterns are included.
     * @param list<string> $ignoredPathPatterns  Configured path patterns to skip unless ignored files are included.
     * @return AnalysisSourceSet Discovered files, parsed units, and load diagnostics.
     */
    public function load(
        string $projectRoot,
        array $paths,
        bool $shouldIncludeIgnored,
        array $ignoredPathPatterns,
    ): AnalysisSourceSet {
        $discoveryResult = (new SourceDiscovery($projectRoot))->discover($paths, $shouldIncludeIgnored, $ignoredPathPatterns);
        $phpFileParser   = new PhpFileParser();
        $diagnostics     = [];
        $analysisUnits   = [];

        foreach ($discoveryResult->missingPaths as $missingPath) {
            $diagnostics[] = new RunDiagnostic(
                type:    'missing-path',
                message: 'Input path does not exist.',
                path:    $missingPath,
            );
        }

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

        return new AnalysisSourceSet($discoveryResult, $analysisUnits, $diagnostics);
    }
}

<?php

declare(strict_types=1);

namespace GruffPhp\Cli\Command;

use GruffPhp\Engine\Analysis\RunDiagnostic;
use GruffPhp\Engine\Parser\PhpFileParser;
use GruffPhp\Engine\Source\SourceDiscovery;
use GruffPhp\Engine\Source\SourceDiscoveryResult;

/**
 * Discovers requested source files and records missing-path diagnostics for an analysis run.
 * `load()` also parses each discovered file and carries fatal errors and nonfatal notes into the source set.
 * `discover()` leaves parsing to the caller, allowing units to be processed and released one at a time.
 */
final readonly class AnalysisSourceLoader
{
    /**
     * Loads discovered files as analysis units, retaining each unit and its diagnostics for batch analysis.
     *
     * @param string $projectRoot - Root used for source discovery and parsing.
     * @param list<string> $paths - Project-relative paths requested by the CLI.
     * @param bool $shouldIncludeIgnored - Whether discovery bypasses optional default and Git ignore filters.
     * @param list<string> $ignoredPathPatterns - Configured patterns excluded even when ignored files are requested.
     * @param array{enabled: bool, maxLines: int, maxBytes: int, override: string}|null $deepScanBudget - Structural-analysis limits.
     *
     * @return AnalysisSourceSet - Discovered files, retained units, and missing-path or parser diagnostics.
     */
    public function load(
        string $projectRoot,
        array  $paths,
        bool   $shouldIncludeIgnored,
        array  $ignoredPathPatterns,
        ?array $deepScanBudget = null,
    ): AnalysisSourceSet {
        $discoveryResult = (new SourceDiscovery($projectRoot))->discover($paths, $shouldIncludeIgnored, $ignoredPathPatterns);
        $phpFileParser   = new PhpFileParser();
        $diagnostics     = $this->missingPathDiagnostics($discoveryResult);
        $analysisUnits   = [];


        foreach ($discoveryResult->files as $file) {
            $unit            = $phpFileParser->parse($file, $deepScanBudget);
            $analysisUnits[] = $unit;


            foreach ($unit->diagnostics as $diagnostic) {
                $diagnostics[] = new RunDiagnostic(
                    type:     $diagnostic->type,
                    message:  $diagnostic->message,
                    filePath: $file->displayPath,
                    line:     $diagnostic->line,
                    isFatal:  $diagnostic->isFatal,
                );
            }
        }

        return new AnalysisSourceSet($discoveryResult, $analysisUnits, $diagnostics);
    }

    /**
     * Discovers files and missing paths without parsing, allowing the caller to process units one at a time.
     *
     * @param string $projectRoot - Root used for source discovery.
     * @param list<string> $paths - Project-relative paths requested by the CLI.
     * @param bool $shouldIncludeIgnored - Whether discovery bypasses optional default and Git ignore filters.
     * @param list<string> $ignoredPathPatterns - Configured patterns excluded even when ignored files are requested.
     *
     * @return array{discovery: SourceDiscoveryResult, diagnostics: list<RunDiagnostic>} - Unparsed files and missing-path diagnostics.
     */
    public function discover(
        string $projectRoot,
        array  $paths,
        bool   $shouldIncludeIgnored,
        array  $ignoredPathPatterns,
    ): array {
        $discoveryResult = (new SourceDiscovery($projectRoot))->discover($paths, $shouldIncludeIgnored, $ignoredPathPatterns);

        return [
            'discovery' => $discoveryResult,
            'diagnostics' => $this->missingPathDiagnostics($discoveryResult),
        ];
    }

    /**
     * Creates one diagnostic for each missing path reported by source discovery.
     *
     * @param SourceDiscoveryResult $sourceDiscoveryResult - Discovery output; each missing path yields one diagnostic.
     *
     * @return list<RunDiagnostic> - One diagnostic per reported missing path, in discovery order; empty when none are missing.
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

        return $diagnostics;
    }
}

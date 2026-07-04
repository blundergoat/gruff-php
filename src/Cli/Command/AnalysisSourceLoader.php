<?php

declare(strict_types=1);

namespace GruffPhp\Cli\Command;

use GruffPhp\Engine\Analysis\RunDiagnostic;
use GruffPhp\Engine\Parser\PhpFileParser;
use GruffPhp\Engine\Source\SourceDiscovery;
use GruffPhp\Engine\Source\SourceDiscoveryResult;

/**
 * Turns the paths a user asked to scan into parsed, ready-to-analyse source files.
 *
 * The first real step of any scan: it discovers the files under the requested paths, parses each one,
 * and records what went wrong (missing paths, parse errors) so the summary and report can tell the
 * user which files were skipped. Offers a batch `load()` and a memory-frugal streaming `discover()`.
 */
final readonly class AnalysisSourceLoader
{
    /**
     * Discovers and parses every requested file in one pass - the simple path for smaller scans.
     *
     * @param string       $projectRoot          - Root used for source discovery and parsing.
     * @param list<string> $paths                - Project-relative paths requested by the CLI.
     * @param bool         $shouldIncludeIgnored - Whether files matching default ignore patterns are included.
     * @param list<string> $ignoredPathPatterns  - Configured path patterns to skip unless ignored files are included.
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

        // Parse each discovered file into an analysis unit the rules can inspect.
        foreach ($discoveryResult->files as $file) {
            $unit            = $phpFileParser->parse($file);
            $analysisUnits[] = $unit;

            // Surface any parse errors as diagnostics, so the user learns a file was skipped rather than clean.
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

    /**
     * Discovers sources without parsing them, so the caller can stream one file at a time.
     *
     * Preferred over load() for large scans: parsing then releasing each unit individually keeps peak
     * memory near a single file's worth instead of holding every AST at once.
     *
     * @param string       $projectRoot          - Root used for source discovery.
     * @param list<string> $paths                - Project-relative paths requested by the CLI.
     * @param bool         $shouldIncludeIgnored - Whether files matching default ignore patterns are included.
     * @param list<string> $ignoredPathPatterns  - Configured path patterns to skip unless ignored files are included.
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

        return [
            'discovery'   => $discoveryResult,
            'diagnostics' => $this->missingPathDiagnostics($discoveryResult),
        ];
    }

    /**
     * Turns paths that vanished between argument parsing and discovery into user-facing diagnostics.
     *
     * @param SourceDiscoveryResult $sourceDiscoveryResult - Discovery output; each missing path yields one diagnostic.
     *
     * @return list<RunDiagnostic> - one missing-path diagnostic per vanished input path, in discovery order; empty when every path resolved
     */
    private function missingPathDiagnostics(SourceDiscoveryResult $sourceDiscoveryResult): array
    {
        $diagnostics = [];
        // One diagnostic per path the user named that no longer exists, so the run reports it as missing.
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

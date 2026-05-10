<?php

declare(strict_types=1);

namespace GruffPhp\Command;

use GruffPhp\Analysis\RunDiagnostic;
use GruffPhp\Parser\PhpFileParser;
use GruffPhp\Source\SourceDiscovery;

final readonly class AnalysisSourceLoader
{
    /**
     * @param list<string> $paths
     * @param list<string> $ignoredPathPatterns
     */
    public function load(
        string $projectRoot,
        array $paths,
        bool $includeIgnored,
        array $ignoredPathPatterns,
    ): AnalysisSourceSet {
        $discoveryResult = (new SourceDiscovery($projectRoot))->discover($paths, $includeIgnored, $ignoredPathPatterns);
        $parser = new PhpFileParser();
        $diagnostics = [];
        $analysisUnits = [];

        foreach ($discoveryResult->missingPaths as $missingPath) {
            $diagnostics[] = new RunDiagnostic(
                type: 'missing-path',
                message: 'Input path does not exist.',
                path: $missingPath,
            );
        }

        foreach ($discoveryResult->files as $file) {
            $unit = $parser->parse($file);
            $analysisUnits[] = $unit;

            foreach ($unit->diagnostics as $diagnostic) {
                $diagnostics[] = new RunDiagnostic(
                    type: 'parse-error',
                    message: $diagnostic->message,
                    filePath: $file->displayPath,
                    line: $diagnostic->line,
                );
            }
        }

        return new AnalysisSourceSet($discoveryResult, $analysisUnits, $diagnostics);
    }
}

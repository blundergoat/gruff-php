<?php

declare(strict_types=1);

namespace GruffPhp\Command;

use GruffPhp\Analysis\RunDiagnostic;
use GruffPhp\Parser\AnalysisUnit;
use GruffPhp\Source\SourceDiscoveryResult;

/**
 * Carries parsed analysis units, diagnostics, and discovery metadata.
 */
final readonly class AnalysisSourceSet
{
    /**
     * @param SourceDiscoveryResult $discovery Discovery result for the requested paths.
     * @param list<AnalysisUnit> $analysisUnits Parsed analysis units.
     * @param list<RunDiagnostic> $diagnostics Diagnostics emitted while loading sources.
     */
    public function __construct(
        public SourceDiscoveryResult $discovery,
        public array $analysisUnits,
        public array $diagnostics,
    ) {
    }

    /**
     * Count successfully parsed analysis units in the loaded source set.
     *
     * @return int Number of units without parse diagnostics.
     */
    public function parsedFileCount(): int
    {
        return count(array_filter(
            $this->analysisUnits,
            static fn (AnalysisUnit $unit): bool => !$unit->hasParseErrors(),
        ));
    }
}

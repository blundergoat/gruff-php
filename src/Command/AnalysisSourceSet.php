<?php

declare(strict_types=1);

namespace GruffPhp\Command;

use GruffPhp\Analysis\RunDiagnostic;
use GruffPhp\Parser\AnalysisUnit;
use GruffPhp\Source\SourceDiscoveryResult;

final readonly class AnalysisSourceSet
{
    /**
     * @param list<AnalysisUnit> $analysisUnits
     * @param list<RunDiagnostic> $diagnostics
     */
    public function __construct(
        public SourceDiscoveryResult $discovery,
        public array $analysisUnits,
        public array $diagnostics,
    ) {
    }

    public function parsedFileCount(): int
    {
        return count(array_filter(
            $this->analysisUnits,
            static fn (AnalysisUnit $unit): bool => !$unit->hasParseErrors(),
        ));
    }
}

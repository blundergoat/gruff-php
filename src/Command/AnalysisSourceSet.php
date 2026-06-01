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
     * Cached count for streaming flows that release each unit's AST before
     * the source set is constructed.
     */
    private ?int $explicitParsedFileCount;

    /**
     * @param SourceDiscoveryResult $discovery       Discovery result for the requested paths.
     * @param list<AnalysisUnit>    $analysisUnits   Parsed analysis units, possibly released.
     * @param list<RunDiagnostic>   $diagnostics     Diagnostics emitted while loading sources.
     * @param int|null              $parsedFileCount Optional pre-computed parsed-file count for streaming flows.
     */
    public function __construct(
        public SourceDiscoveryResult $discovery,
        public array $analysisUnits,
        public array $diagnostics,
        ?int $parsedFileCount = null,
    ) {
        $this->explicitParsedFileCount = $parsedFileCount;
    }

    /**
     * Count successfully parsed analysis units in the loaded source set.
     *
     * @return int Number of units without parse diagnostics.
     */
    public function parsedFileCount(): int
    {
        if ($this->explicitParsedFileCount !== null) {
            // Streaming flows release each unit's AST before constructing the set, so the
            // live units are no longer countable; trust the count captured at parse time.
            return $this->explicitParsedFileCount;
        }

        // No pre-computed count, so the units are still in memory: derive it by excluding
        // units that failed to parse, matching how non-streaming callers measure coverage.
        return count(array_filter(
            $this->analysisUnits,
            static fn (AnalysisUnit $analysisUnit): bool => !$analysisUnit->hasParseErrors(),
        ));
    }
}

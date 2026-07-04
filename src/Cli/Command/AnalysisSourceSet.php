<?php

declare(strict_types=1);

namespace GruffPhp\Cli\Command;

use GruffPhp\Engine\Analysis\RunDiagnostic;
use GruffPhp\Engine\Parser\AnalysisUnit;
use GruffPhp\Engine\Source\SourceDiscoveryResult;
use GruffPhp\Engine\Source\SourceFile;

/**
 * The parsed sources for one analysis run - units, diagnostics, and discovery metadata.
 *
 * Sits between source loading and rule execution: it holds the parsed `AnalysisUnit`s the rules
 * inspect, the diagnostics (like parse errors) surfaced to the user, and the discovery result behind
 * the "N discovered / parsed / ignored / missing" counts shown in the summary and report.
 */
final readonly class AnalysisSourceSet
{
    /**
     * Cached count for streaming flows that release each unit's AST before
     * the source set is constructed.
     */
    private ?int $explicitParsedFileCount;

    /**
     * Bundles one run's discovered files and parsed units with the counts the user ultimately sees.
     *
     * @param SourceDiscoveryResult $discovery       - Discovery result for the requested paths.
     * @param list<AnalysisUnit>    $analysisUnits   - Parsed analysis units, possibly released.
     * @param list<RunDiagnostic>   $diagnostics     - Diagnostics emitted while loading sources.
     * @param int|null              $parsedFileCount - Pre-computed parsed-file count for streaming flows; null derives it live from the units.
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
     * Lists the project-relative paths of the discovered files - the paths shown beside each finding.
     *
     * @return list<string> - Project-relative source file paths in discovery order.
     */
    public function displayPaths(): array
    {
        return array_map(
            static fn (SourceFile $sourceFile): string => $sourceFile->displayPath,
            $this->discovery->files,
        );
    }

    /**
     * Counts the files gruff actually parsed, feeding the "N parsed" figure in the summary and report.
     *
     * @return int - Number of units without parse diagnostics.
     */
    public function parsedFileCount(): int
    {
        // Streaming flows release each unit's AST before constructing the set, so the live units are
        // no longer countable; trust the count captured at parse time.
        if ($this->explicitParsedFileCount !== null) {
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

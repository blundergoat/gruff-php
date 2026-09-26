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

    /** Ratified scoring denominator captured at parse time, when the units themselves were released. */
    private ?int $explicitEvaluatedFileCount;

    /**
     * Bundles one run's discovered files and parsed units with the counts the user ultimately sees.
     *
     * @param SourceDiscoveryResult $discovery       - Discovery result for the requested paths.
     * @param list<AnalysisUnit>    $analysisUnits   - Parsed analysis units, possibly released.
     * @param list<RunDiagnostic>   $diagnostics     - Diagnostics emitted while loading sources.
     * @param int|null              $parsedFileCount - Pre-computed parsed-file count for streaming flows; null derives it live from the units.
     * @param int|null              $evaluatedFileCount - Pre-computed ratified scoring denominator for streaming flows; null derives it live.
     */
    public function __construct(
        public SourceDiscoveryResult $discovery,
        public array $analysisUnits,
        public array $diagnostics,
        ?int $parsedFileCount = null,
        ?int $evaluatedFileCount = null,
    ) {
        $this->explicitParsedFileCount    = $parsedFileCount;
        $this->explicitEvaluatedFileCount = $evaluatedFileCount;
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
    /**
     * Counts the PHP files this run actually evaluated - the ratified scoring denominator.
     *
     * Deliberately narrower than parsedFileCount(), which also counts the text inputs raw-text rules
     * read: a README is scanned but carries no PHP to score, so including it would divide real
     * findings by files no PHP rule ever evaluated and quietly flatter every project holding docs.
     *
     * @return int - PHP files that parsed successfully; zero means nothing was evaluated and no score can be reported.
     */
    public function evaluatedFileCount(): int
    {
        // Streaming flows release each unit after parsing it, so the count captured then is all there is.
        if ($this->explicitEvaluatedFileCount !== null) {
            return $this->explicitEvaluatedFileCount;
        }

        return count(array_filter(
            $this->analysisUnits,
            static fn (AnalysisUnit $analysisUnit): bool => !$analysisUnit->hasParseErrors() && $analysisUnit->file->isPhp(),
        ));
    }

    /**
     * Counts every file this run parsed successfully, including the text inputs raw-text rules read.
     *
     * This is the coverage number the user sees beside the scan; the ratified scoring denominator is
     * the narrower evaluatedFileCount().
     *
     * @return int - Files that parsed without error, of any supported type.
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

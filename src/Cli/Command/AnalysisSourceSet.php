<?php

declare(strict_types=1);

namespace GruffPhp\Cli\Command;

use GruffPhp\Engine\Analysis\RunDiagnostic;
use GruffPhp\Engine\Parser\AnalysisUnit;
use GruffPhp\Engine\Source\SourceDiscoveryResult;
use GruffPhp\Engine\Source\SourceFile;

/**
 * Carries discovery metadata, diagnostics, and file counts for one analysis run.
 *
 * Batch runs retain analysis units; streaming runs can supply counts after releasing those units.
 * Parsed-file coverage includes text inputs. Only PHP files that received structural analysis count toward the score.
 */
final readonly class AnalysisSourceSet
{
    /**
     * Cached count for streaming flows that release each unit's AST before the source set is constructed.
     */
    private ?int $explicitParsedFileCount;

    /** Ratified scoring denominator captured at parse time, when the units themselves were released. */
    private ?int $explicitEvaluatedFileCount;

    /**
     * Bundles one run's discovered files and parsed units with the counts the user ultimately sees.
     *
     * @param SourceDiscoveryResult $discovery          - Discovery result for the requested paths.
     * @param list<AnalysisUnit>    $analysisUnits      - Parsed analysis units, possibly released.
     * @param list<RunDiagnostic>   $diagnostics        - Diagnostics emitted while loading sources.
     * @param int|null              $parsedFileCount    - Pre-computed parsed-file count for streaming flows; null derives it live from the units.
     * @param int|null              $evaluatedFileCount - Pre-computed eligible PHP-file count for streaming flows; null derives it live.
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
     * Counts PHP inputs that reached structural analysis for the scoring denominator.
     * Text-only and bounded inputs still contribute to parsedFileCount().
     *
     * @return int - The streaming count or the number of retained PHP units with a complete parse.
     */
    public function evaluatedFileCount(): int
    {
        // Streaming flows release each unit after parsing it, so the count captured then is all there is.
        if ($this->explicitEvaluatedFileCount !== null) {
            return $this->explicitEvaluatedFileCount;
        }

        return count(array_filter(
            $this->analysisUnits,
            static fn (AnalysisUnit $analysisUnit): bool => $analysisUnit->file->isPhp()
                && !$analysisUnit->hasParseErrors() && !$analysisUnit->isDeepScanBounded(),
        ));
    }

    /**
     * Counts supported inputs accepted without fatal parse diagnostics.
     *
     * Uses a supplied streaming count when present; otherwise derives coverage from retained units.
     * The scoring denominator is the narrower evaluatedFileCount().
     *
     * @return int - Coverage count, including text inputs and PHP files with nonfatal budget notes.
     */
    public function parsedFileCount(): int
    {
        // Streaming flows release each unit's AST before constructing the set, so the live units are
        // no longer countable; trust the count captured at parse time.
        if ($this->explicitParsedFileCount !== null) {
            return $this->explicitParsedFileCount;
        }



        return count(array_filter(
            $this->analysisUnits,
            static fn (AnalysisUnit $analysisUnit): bool => !$analysisUnit->hasParseErrors(),
        ));
    }
}

<?php

declare(strict_types=1);

namespace GruffPhp\Cli\Command;

use GruffPhp\Results\Scoring\FileScore;
use GruffPhp\Results\Scoring\ScoreReport;

/**
 * Carries all data needed to render text or JSON summary output.
 */
final readonly class SummaryReportData
{
    /**
     * Capture source, score, and aggregate finding data for summary rendering.
     *
     * @param list<string>                                                                                     $paths - Paths requested by the summary command.
     * @param string|null                                                                                      $configPath - Effective config path used for analysis.
     * @param int                                                                                              $sourcesDiscovered - Number of files discovered before parsing.
     * @param int                                                                                              $sourcesParsed - Number of files parsed without parse errors.
     * @param int                                                                                              $ignoredPaths - Number of ignored paths reported by discovery.
     * @param int                                                                                              $missingPaths - Number of missing input paths.
     * @param int                                                                                              $parseErrors - Number of parse-error diagnostics.
     * @param ScoreReport                                                                                      $score - Composite score report for the run.
     * @param array{advisory: int, warning: int, error: int, total: int}                                       $totals - Finding totals by severity.
     * @param list<array{ruleId: string, count: int, advisory: int, warning: int, error: int, pillar: string}> $topRules - Highest-volume rules.
     * @param list<FileScore>                                                                                  $topOffenders - Lowest-scoring file summaries.
     */
    public function __construct(
        public array $paths,
        public ?string $configPath,
        public int $sourcesDiscovered,
        public int $sourcesParsed,
        public int $ignoredPaths,
        public int $missingPaths,
        public int $parseErrors,
        public ScoreReport $score,
        public array $totals,
        public array $topRules,
        public array $topOffenders,
    ) {
    }
}

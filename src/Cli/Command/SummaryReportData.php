<?php

declare(strict_types=1);

namespace GruffPhp\Cli\Command;

use GruffPhp\Results\Scoring\FileScore;
use GruffPhp\Results\Scoring\ScoreReport;

/**
 * Immutable payload the `summary` command hands to its renderers.
 *
 * Bundles everything one summary needs - scanned paths, file counts, composite score, severity
 * totals, and the top-rules/top-offenders lists - so the text and JSON renderers read identical
 * numbers and the user sees a consistent digest whichever format they chose.
 */
final readonly class SummaryReportData
{
    /**
     * Capture source, score, and aggregate finding data for one summary render.
     *
     * @param list<string>                                                                                     $paths             - Paths the user named; empty means none were given, so the whole project was scanned.
     * @param string|null                                                                                      $configPath        - Effective config path for the run; null when no config file was used.
     * @param int                                                                                              $sourcesDiscovered - Number of files discovered before parsing.
     * @param int                                                                                              $sourcesParsed     - Number of files parsed without parse errors.
     * @param int                                                                                              $ignoredPaths      - Number of ignored paths reported by discovery.
     * @param int                                                                                              $missingPaths      - Number of missing input paths.
     * @param int                                                                                              $parseErrors       - Number of parse-error diagnostics.
     * @param ScoreReport                                                                                      $score             - Composite score report for the run.
     * @param array{advisory: int, warning: int, error: int, total: int}                                       $totals            - Finding totals by severity.
     * @param list<array{ruleId: string, count: int, advisory: int, warning: int, error: int, pillar: string}> $topRules          - Highest-volume rules; empty when the run had no findings.
     * @param list<FileScore>                                                                                  $topOffenders      - Lowest-scoring files; empty when nothing scored below the pass mark.
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

<?php

declare(strict_types=1);

namespace GruffPhp\Cli\Command;

use GruffPhp\Engine\Analysis\AnalysisReport;
use GruffPhp\Engine\Analysis\SensitiveExclusionSummary;
use GruffPhp\Engine\Analysis\RunDiagnostic;
use GruffPhp\Results\Scoring\FileScore;
use GruffPhp\Results\Scoring\ScoreReport;

/**
 * Carries canonical run data and the aggregates used by the summary renderers.
 *
 * The JSON renderer projects analysisReport; the text renderer reads the supplied counts, scores, and ranked lists.
 * The container stores these values without deriving them from one another.
 */
final readonly class SummaryReportData
{
    /**
     * Capture source, score, and aggregate finding data for one summary render.
     *
     * @param list<string> $paths - Scan operands; empty selects the default project scan.
     * @param string|null $configPath - Effective config path for the run; null when no config file was used.
     * @param AnalysisReport $analysisReport - Canonical model projected by the JSON renderer.
     * @param int $sourcesDiscovered - Number of files discovered before parsing.
     * @param int $sourcesParsed - Number of files parsed without parse errors.
     * @param int $ignoredPaths - Number of ignored paths reported by discovery.
     * @param int $missingPaths - Number of missing input paths.
     * @param int $parseErrors - Number of parse-error diagnostics.
     * @param ScoreReport $score - Composite score report for the run.
     * @param array{advisory: int, warning: int, error: int, total: int} $totals - Finding totals by severity.
     * @param list<array{ruleId: string, count: int, advisory: int, warning: int, error: int, pillar: string}> $topRules - Rule rows kept by --top.
     * @param list<FileScore> $topOffenders - Ranked scored files retained for display; empty when none are retained.
     * @param list<SensitiveExclusionSummary> $sensitiveExclusions - Suppression audit rows, including built-in removals.
     * @param list<RunDiagnostic> $diagnostics - Diagnostics printed by the text renderer; empty omits its diagnostics block.
     */
    public function __construct(
        public array $paths,
        public ?string $configPath,
        public AnalysisReport $analysisReport,
        public int $sourcesDiscovered,
        public int $sourcesParsed,
        public int $ignoredPaths,
        public int $missingPaths,
        public int $parseErrors,
        public ScoreReport $score,
        public array $totals,
        public array $topRules,
        public array $topOffenders,
        public array $sensitiveExclusions = [],
        public array $diagnostics = [],
    ) {
    }
}

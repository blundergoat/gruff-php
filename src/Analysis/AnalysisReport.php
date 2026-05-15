<?php

declare(strict_types=1);

namespace GruffPhp\Analysis;

use GruffPhp\Baseline\BaselineReport;
use GruffPhp\Diff\DiffResult;
use GruffPhp\Finding\Finding;
use GruffPhp\Finding\Severity;
use GruffPhp\Mutation\MutationAnalysisResult;
use GruffPhp\Reporting\FindingDisplayFilter;
use GruffPhp\Review\BranchReviewResult;
use GruffPhp\Scoring\ScoreReport;
use GruffPhp\Trend\TrendReport;

/**
 * Carries the full analysis result used by every reporter format.
 *
 * @phpstan-type ReportScalar bool|float|int|object|string|null
 * @phpstan-type ReportValue ReportScalar|array<array-key, ReportScalar|array<array-key, ReportScalar|array<array-key, ReportScalar|array<array-key, ReportScalar|array<array-key, ReportScalar>>>>>
 */
final readonly class AnalysisReport
{
    /**
     * Tool name emitted in human-readable and machine-readable reports.
     */
    public const TOOL_NAME = 'gruff-php';

    /**
     * Stable schema identifier emitted in machine-readable reports.
     */
    public const SCHEMA_VERSION = 'gruff.analysis.v1';

    /**
     * @param string                      $toolVersion     Gruff version used to produce the report.
     * @param list<string>                $requestedPaths  Paths requested for analysis.
     * @param string                      $format          Output format requested for report serialization.
     * @param string                      $failOn          Severity gate used to determine the process exit code.
     * @param int                         $filesDiscovered Number of source files discovered before parsing.
     * @param int                         $filesParsed     Number of source files parsed successfully.
     * @param list<string>                $ignoredPaths    Requested paths ignored by discovery.
     * @param list<string>                $missingPaths    Requested paths that did not resolve to files.
     * @param list<RunDiagnostic>         $diagnostics     Non-finding diagnostics emitted during the run.
     * @param list<Finding>               $findings        Findings included in the report.
     * @param int                         $exitCode        Process exit code represented by the report.
     * @param string|null                 $configPath      Config file path used for the run, when available.
     * @param MutationAnalysisResult|null $mutation        Mutation analysis result attached to the report.
     * @param ScoreReport|null            $score           Score summary attached to the report.
     * @param DiffResult|null             $diff            Diff context attached to the report.
     * @param TrendReport|null            $trend           Trend history attached to the report.
     * @param BaselineReport|null         $baseline        Baseline application result attached to the report.
     * @param BranchReviewResult|null     $review          Branch review result attached to the report.
     * @param FindingDisplayFilter|null   $filters         Display filters applied to the report output.
     */
    public function __construct(
        public string $toolVersion,
        public array $requestedPaths,
        public string $format,
        public string $failOn,
        public int $filesDiscovered,
        public int $filesParsed,
        public array $ignoredPaths,
        public array $missingPaths,
        public array $diagnostics,
        public array $findings,
        public int $exitCode,
        public ?string $configPath = null,
        public ?MutationAnalysisResult $mutation = null,
        public ?ScoreReport $score = null,
        public ?DiffResult $diff = null,
        public ?TrendReport $trend = null,
        public ?BaselineReport $baseline = null,
        public ?BranchReviewResult $review = null,
        public ?FindingDisplayFilter $filters = null,
    ) {
    }

    /**
     * @return array{advisory: int, warning: int, error: int, total: int}
     */
    public function findingCounts(): array
    {
        $counts = [
            'advisory' => 0,
            'warning' => 0,
            'error' => 0,
            'total' => count($this->findings),
        ];

        foreach ($this->findings as $finding) {
            $counts[$finding->severity->value]++;
        }

        return $counts;
    }

    /**
     * Count parse diagnostics emitted while loading analysed files.
     *
     * @return int Number of parse-error diagnostics in the report.
     */
    public function parseErrorCount(): int
    {
        return count(array_filter(
            $this->diagnostics,
            static fn (RunDiagnostic $diagnostic): bool => $diagnostic->type === 'parse-error',
        ));
    }

    /**
     * @return array<string, ReportValue>
     */
    public function toArray(): array
    {
        $report = [
            'schemaVersion' => self::SCHEMA_VERSION,
            'tool' => [
                'name' => self::TOOL_NAME,
                'version' => $this->toolVersion,
            ],
            'run' => [
                'format' => $this->format,
                'failOn' => $this->failOn,
                'config' => $this->configPath,
                'paths' => $this->requestedPaths,
                'filters' => $this->filters?->toArray(),
            ],
            'summary' => [
                'filesDiscovered' => $this->filesDiscovered,
                'filesParsed' => $this->filesParsed,
                'ignoredPaths' => count($this->ignoredPaths),
                'missingPaths' => count($this->missingPaths),
                'parseErrors' => $this->parseErrorCount(),
                'findings' => $this->findingCounts(),
                'exitCode' => $this->exitCode,
            ],
            'ignoredPaths' => $this->ignoredPaths,
            'missingPaths' => $this->missingPaths,
            'diagnostics' => array_map(
                static fn (RunDiagnostic $diagnostic): array => $diagnostic->toArray(),
                $this->diagnostics,
            ),
            'findings' => array_map(
                static fn (Finding $finding): array => $finding->toArray(),
                $this->findings,
            ),
        ];

        if ($this->mutation instanceof MutationAnalysisResult) {
            $report['mutation'] = $this->mutation->toArray();
        }

        if ($this->score instanceof ScoreReport) {
            $report['score'] = $this->score->toArray();
        }

        if ($this->diff instanceof DiffResult) {
            $report['diff'] = $this->diff->toArray();
        }

        if ($this->trend instanceof TrendReport) {
            $report['trend'] = $this->trend->toArray();
        }

        if ($this->baseline instanceof BaselineReport) {
            $report['baseline'] = $this->baseline->toArray();
        }

        if ($this->review instanceof BranchReviewResult) {
            $report['review'] = $this->review->toArray();
        }

        return $report;
    }

    /**
     * Check whether any finding in the report matches the requested severity.
     *
     * @param Severity $severity Severity level to look for in the finding list.
     * @return bool True when at least one finding has the requested severity.
     */
    public function hasFindingsAtSeverity(Severity $severity): bool
    {
        foreach ($this->findings as $finding) {
            if ($finding->severity === $severity) {
                return true;
            }
        }

        return false;
    }
}

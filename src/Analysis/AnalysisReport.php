<?php

declare(strict_types=1);

namespace GruffPhp\Analysis;

use GruffPhp\Finding\Finding;
use GruffPhp\Finding\Severity;
use GruffPhp\Baseline\BaselineReport;
use GruffPhp\Mutation\MutationAnalysisResult;
use GruffPhp\Diff\DiffResult;
use GruffPhp\Reporting\FindingDisplayFilter;
use GruffPhp\Review\BranchReviewResult;
use GruffPhp\Scoring\ScoreReport;
use GruffPhp\Trend\TrendReport;

final readonly class AnalysisReport
{
    public const SCHEMA_VERSION = 'gruff.analysis.v1';

    /**
     * @param list<string> $requestedPaths
     * @param list<string> $ignoredPaths
     * @param list<string> $missingPaths
     * @param list<RunDiagnostic> $diagnostics
     * @param list<Finding> $findings
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
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        $report = [
            'schemaVersion' => self::SCHEMA_VERSION,
            'tool' => [
                'name' => 'gruff',
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

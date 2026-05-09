<?php

declare(strict_types=1);

namespace GruffPhp\Analysis;

use GruffPhp\Finding\Finding;
use GruffPhp\Finding\Severity;
use GruffPhp\Mutation\MutationAnalysisResult;
use GruffPhp\Diff\DiffResult;
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

        return $report;
    }

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

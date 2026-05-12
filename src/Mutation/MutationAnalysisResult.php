<?php

declare(strict_types=1);

namespace GruffPhp\Mutation;

/**
 * Carries mutation metrics attached to an analysis report.
 */
final readonly class MutationAnalysisResult
{
    /**
     * Create a mutation analysis result with optional baseline and budget context.
     */
    public function __construct(
        public InfectionReport $report,
        public ?InfectionReport $baselineReport = null,
        public ?int $mutationBudget = null,
    ) {
    }

    /**
     * Count escaped and timed-out mutants in the current report.
     *
     * @return int Survived mutant count.
     */
    public function survivedCount(): int
    {
        return count($this->report->survivedMutants());
    }

    /**
     * Check whether the survived mutant count exceeds the configured budget.
     *
     * @return bool True when the mutation budget is exceeded.
     */
    public function budgetExceeded(): bool
    {
        return $this->mutationBudget !== null && $this->survivedCount() > $this->mutationBudget;
    }

    /**
     * Calculate the mutation score delta versus the baseline report.
     *
     * @return float|null MSI delta, or null when no baseline report exists.
     */
    public function msiDelta(): ?float
    {
        if (!$this->baselineReport instanceof InfectionReport) {
            return null;
        }

        return round($this->report->msi() - $this->baselineReport->msi(), 2);
    }

    /**
     * @return array{
     *     source: string,
     *     stats: array<string, int|float>,
     *     totals: array{totalMutants: int, survivedMutants: int, msi: float, coveredMsi: float, mutationCodeCoverage: float},
     *     files: list<array{
     *         file: string,
     *         totalMutants: int,
     *         killedMutants: int,
     *         survivedMutants: int,
     *         notCoveredMutants: int,
     *         msi: float,
     *         coveredMsi: float
     *     }>,
     *     survivedMutants: list<array{status: string, file: string, line: int|null, mutator: string, diff: string|null, processOutput: string|null}>,
     *     baseline: array{source: string, msi: float, delta: float}|null,
     *     budget: array{limit: int, survivedMutants: int, exceeded: bool}|null
     * }
     */
    public function toArray(): array
    {
        return [
            'source' => $this->report->reportPath,
            'stats' => $this->report->stats,
            'totals' => [
                'totalMutants' => $this->report->totalMutants(),
                'survivedMutants' => $this->survivedCount(),
                'msi' => $this->report->msi(),
                'coveredMsi' => $this->report->coveredMsi(),
                'mutationCodeCoverage' => $this->report->coverageRate(),
            ],
            'files' => array_map(
                static fn (MutationFileSummary $summary): array => $summary->toArray(),
                $this->report->fileSummaries(),
            ),
            'survivedMutants' => array_map(
                static fn (InfectionMutant $mutant): array => $mutant->toArray(),
                $this->report->survivedMutants(),
            ),
            'baseline' => $this->baselineReport instanceof InfectionReport
                ? [
                    'source' => $this->baselineReport->reportPath,
                    'msi' => $this->baselineReport->msi(),
                    'delta' => $this->msiDelta() ?? 0.0,
                ]
                : null,
            'budget' => $this->mutationBudget === null
                ? null
                : [
                    'limit' => $this->mutationBudget,
                    'survivedMutants' => $this->survivedCount(),
                    'exceeded' => $this->budgetExceeded(),
                ],
        ];
    }
}

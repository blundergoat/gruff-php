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
     *
     * @param InfectionReport      $report - Current mutation report.
     * @param InfectionReport|null $baselineReport - Baseline mutation report, when supplied.
     * @param int|null             $mutationBudget - Allowed survived-mutant budget, when configured.
     */
    public function __construct(
        public InfectionReport  $report,
        public ?InfectionReport $baselineReport = null,
        public ?int             $mutationBudget = null,
    ) {
    }

    /**
     * Count escaped and timed-out mutants in the current report.
     *
     * @return int - mutants the suite failed to kill (escaped plus timed-out); the figure the budget is measured against
     */
    public function survivedCount(): int
    {
        // Survivors are mutants the suite failed to kill; this is the figure the budget is measured against.
        return count($this->report->survivedMutants());
    }

    /**
     * Check whether the survived mutant count exceeds the configured budget.
     *
     * @return bool - true when survivors strictly exceed the budget; false when no budget is set or the cap is hit exactly
     */
    public function isBudgetExceeded(): bool
    {
        // No configured budget can never be exceeded; the strict greater-than means hitting the cap exactly is allowed.
        return $this->mutationBudget !== null && $this->survivedCount() > $this->mutationBudget;
    }

    /**
     * Calculate the mutation score delta versus the baseline report.
     *
     * @return float|null - current minus baseline MSI rounded to two places (positive means improved); null when no baseline exists
     */
    public function msiDelta(): ?float
    {
        if (!$this->baselineReport instanceof InfectionReport) {
            // Null means "no baseline to compare", which callers render as no delta rather than a zero change.
            return null;
        }

        // Positive delta means MSI improved over the baseline; rounded to two places to match reported precision.
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
     * } - serialisable snapshot of the report; baseline and budget keys are null when no baseline or budget is set
     */
    public function toArray(): array
    {
        // Flat serialisable snapshot for report consumers; baseline and budget keys collapse to null when unset.
        return [
            'source'          => $this->report->reportPath,
            'stats'           => $this->report->stats,
            'totals'          => [
                'totalMutants'         => $this->report->totalMutants(),
                'survivedMutants'      => $this->survivedCount(),
                'msi'                  => $this->report->msi(),
                'coveredMsi'           => $this->report->coveredMsi(),
                'mutationCodeCoverage' => $this->report->coverageRate(),
            ],
            'files'           => array_map(
                static fn(MutationFileSummary $mutationFileSummary): array => $mutationFileSummary->toArray(),
                $this->report->fileSummaries(),
            ),
            'survivedMutants' => array_map(
                static fn(InfectionMutant $infectionMutant): array => $infectionMutant->toArray(),
                $this->report->survivedMutants(),
            ),
            'baseline'        => $this->baselineReport instanceof InfectionReport
                ? [
                    'source' => $this->baselineReport->reportPath,
                    'msi'    => $this->baselineReport->msi(),
                    'delta'  => $this->msiDelta() ?? 0.0,
                ]
                : null,
            'budget'          => $this->mutationBudget === null
                ? null
                : [
                    'limit'           => $this->mutationBudget,
                    'survivedMutants' => $this->survivedCount(),
                    'exceeded'        => $this->isBudgetExceeded(),
                ],
        ];
    }
}

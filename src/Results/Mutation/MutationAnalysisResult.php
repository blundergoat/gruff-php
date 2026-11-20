<?php

declare(strict_types=1);

namespace GruffPhp\Results\Mutation;

/**
 * Combines a current mutation report with an optional baseline and survivor budget.
 * Reporters consume its counts, score delta, budget status, and serialized data.
 *
 * Escaped and timed-out mutants count as survivors for the budget.
 */
final readonly class MutationAnalysisResult
{
    /**
     * Bundles the current mutation report with the optional baseline and survivor budget.
     *
     * @param InfectionReport $report - Current report supplying counts and scores.
     * @param InfectionReport|null $baselineReport - Earlier report for MSI comparison; null leaves the delta unavailable.
     * @param int|null $mutationBudget - Maximum tolerated survivors; null disables the budget check.
     */
    public function __construct(
        public InfectionReport  $report,
        public ?InfectionReport $baselineReport = null,
        public ?int             $mutationBudget = null,
    ) {
    }

    /**
     * Counts the mutations that slipped past the suite - the one number the user's budget is checked against.
     *
     * @return int - Mutants the suite failed to kill (escaped plus timed-out); the figure the budget is measured against.
     */
    public function survivedCount(): int
    {
        return count($this->report->survivedMutants());
    }

    /**
     * Reports whether escaped and timed-out mutants strictly exceed the configured budget.
     *
     * @return bool - True when survivors strictly exceed the budget; false when no budget is set or the cap is hit exactly.
     */
    public function isBudgetExceeded(): bool
    {

        return $this->mutationBudget !== null && $this->survivedCount() > $this->mutationBudget;
    }

    /**
     * Calculates the current report's MSI change relative to the optional baseline.
     *
     * @return float|null - Current minus baseline MSI, rounded to two decimal places; null without a baseline.
     */
    public function msiDelta(): ?float
    {

        if (!$this->baselineReport instanceof InfectionReport) {
            return null;
        }

        return round($this->report->msi() - $this->baselineReport->msi(), 2);
    }

    /**
     * Flattens the whole mutation verdict into the JSON shape the report writers emit, so a script or
     * editor gets the same survivors, scores, and budget status a person reads in the terminal.
     *
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
     * } - serialisable snapshot of the report; the baseline and budget keys are null when the user set no baseline or no budget.
     */
    public function toArray(): array
    {
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

<?php

declare(strict_types=1);

namespace GruffPhp\Results\Mutation;

/**
 * The mutation-testing verdict attached to one analysis run - the survivors, the budget check, and
 * the score movement versus a baseline, bundled for the reporters to render.
 *
 * A user sees this whenever mutation analysis is active (they pointed gruff at an Infection report or
 * asked it to run Infection): it wraps the parsed current report, an optional earlier one to compare
 * against, and the survived-mutant budget they set. From it the run answers the three questions the
 * user actually cares about - how many mutations slipped past the tests, whether that breaks their
 * budget, and whether the score moved since last time.
 */
final readonly class MutationAnalysisResult
{
    /**
     * Bundles the current mutation report with the optional baseline and budget the run gates on.
     *
     * @param InfectionReport      $report - The parsed mutation report for this run; the source of every survivor and score figure shown.
     * @param InfectionReport|null $baselineReport - Earlier report to measure movement against; null when the user set no mutation baseline, so no delta is shown.
     * @param int|null             $mutationBudget - Most surviving mutants the run tolerates before it fails; null when the user set no budget, so survivors only report.
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
     * Reports whether the user's survived-mutant budget was blown, which is what turns mutation
     * analysis into a failed run rather than a passing one.
     *
     * @return bool - True when survivors strictly exceed the budget; false when no budget is set or the cap is hit exactly.
     */
    public function isBudgetExceeded(): bool
    {
        // With no budget set there is nothing to breach; otherwise the run fails only once survivors pass the cap.
        return $this->mutationBudget !== null && $this->survivedCount() > $this->mutationBudget;
    }

    /**
     * Reports how far the mutation score moved since the baseline, so the user can see at a glance
     * whether their tests are getting better or worse at catching mutations.
     *
     * @return float|null - Current minus baseline MSI, rounded to two places (positive means improved); null when the user set no baseline to compare against.
     */
    public function msiDelta(): ?float
    {
        // No baseline report means there is nothing to compare against, so there is no movement to show.
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
                    // A baseline with no comparable score reports 0.0 movement rather than a null the reader must handle.
                    'delta'  => $this->msiDelta() ?? 0.0,
                ]
                : null,
            // No budget set means a null budget block, telling the reader this run was never gated on survivors.
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

<?php

declare(strict_types=1);

namespace GruffPhp\Mutation;

final readonly class MutationAnalysisResult
{
    public function __construct(
        public InfectionReport $report,
        public ?InfectionReport $baselineReport = null,
        public ?int $mutationBudget = null,
    ) {
    }

    public function survivedCount(): int
    {
        return count($this->report->survivedMutants());
    }

    public function budgetExceeded(): bool
    {
        return $this->mutationBudget !== null && $this->survivedCount() > $this->mutationBudget;
    }

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

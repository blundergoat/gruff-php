<?php

declare(strict_types=1);

namespace GruffPhp\Results\Mutation;

/**
 * Stores mutant counts and MSI percentages for one source file.
 * InfectionReport calculates these rows from mutant statuses for per-file reporting.
 *
 * Killed counts include both test kills and static-analysis kills.
 */
final readonly class MutationFileSummary
{
    /**
     * Stores the per-file counts and scores calculated by InfectionReport.
     *
     * @param string $filePath - Display path identifying the mutated source file.
     * @param int $totalMutants - Number of mutant records for this file, including statuses outside the score categories.
     * @param int $killedMutants - Mutants killed by tests or static analysis.
     * @param int $survivedMutants - Escaped or timed-out mutants.
     * @param int $notCoveredMutants - Mutants recorded as not covered; included in MSI but excluded from covered-code MSI.
     * @param float $msi - Per-file MSI percentage, or 0.0 for an empty denominator.
     * @param float $coveredMsi - Per-file MSI over kills and survivors only, or 0.0 when neither occurs.
     */
    public function __construct(
        public string $filePath,
        public int    $totalMutants,
        public int    $killedMutants,
        public int    $survivedMutants,
        public int    $notCoveredMutants,
        public float  $msi,
        public float  $coveredMsi,
    ) {
    }

    /**
     * Serializes the file path, mutant counts, and MSI percentages for report output.
     *
     * @return array{file: string, totalMutants: int, killedMutants: int, survivedMutants: int, notCoveredMutants: int, msi: float, coveredMsi: float}
     *     - Per-file report row containing the display path, mutant counts, and MSI percentages.
     */
    public function toArray(): array
    {
        return [
            'file'              => $this->filePath,
            'totalMutants'      => $this->totalMutants,
            'killedMutants'     => $this->killedMutants,
            'survivedMutants'   => $this->survivedMutants,
            'notCoveredMutants' => $this->notCoveredMutants,
            'msi'               => $this->msi,
            'coveredMsi'        => $this->coveredMsi,
        ];
    }
}

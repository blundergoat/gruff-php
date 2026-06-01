<?php

declare(strict_types=1);

namespace GruffPhp\Mutation;

/**
 * Summarises mutation outcomes for one source file.
 */
final readonly class MutationFileSummary
{
    /**
     * Capture per-file mutation testing totals and MSI percentages.
     *
     * @param string $filePath          Display path for the mutated source file.
     * @param int    $totalMutants      Total mutants reported for the file.
     * @param int    $killedMutants     Mutants killed by the test suite.
     * @param int    $survivedMutants   Mutants that survived the test suite.
     * @param int    $notCoveredMutants Mutants not covered by tests.
     * @param float  $msi               Mutation score indicator percentage.
     * @param float  $coveredMsi        Covered-code mutation score indicator percentage.
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
     * @return array{
     *     file: string,
     *     totalMutants: int,
     *     killedMutants: int,
     *     survivedMutants: int,
     *     notCoveredMutants: int,
     *     msi: float,
     *     coveredMsi: float
     * } - serialisable per-file row for report output; 'file' holds the display path, counts are raw mutant tallies, and the two MSI values are
     * percentages (0-100)
     */
    public function toArray(): array
    {
        // Serialisable per-file row; the 'file' key carries the display path while the constructor field is $filePath.
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

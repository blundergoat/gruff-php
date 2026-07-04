<?php

declare(strict_types=1);

namespace GruffPhp\Results\Mutation;

/**
 * The mutation outcome for one source file - its mutant tallies and the two MSI percentages, ready for
 * the per-file rows of a mutation report.
 *
 * When a user reads mutation feedback, they want to know which files their tests guard well and which
 * let mutations slip through. This readonly row carries exactly that for a single file: how many
 * mutants were tried, how many the tests killed, how many survived, how many no test even covered, and
 * the resulting scores - so the report can point the user straight at the weakest-tested files.
 */
final readonly class MutationFileSummary
{
    /**
     * Captures one file's mutation tallies and scores, as parsed from the Infection report.
     *
     * @param string $filePath - Display path of the mutated source file, so the user sees which file the row describes.
     * @param int    $totalMutants - Total mutants Infection tried in this file.
     * @param int    $killedMutants - Mutants the test suite caught.
     * @param int    $survivedMutants - Mutants that slipped past the tests - the gaps the user may want to close.
     * @param int    $notCoveredMutants - Mutants on code no test exercises at all, which drag the file's score down.
     * @param float  $msi - Mutation score indicator for the file, as a 0-100 percentage.
     * @param float  $coveredMsi - Mutation score over only the code the tests actually reach, as a 0-100 percentage.
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
     * Flattens the file row into the map a mutation report serialises, so each file becomes one tidy
     * JSON entry a script or editor can read.
     *
     * @return array{
     *     file: string,
     *     totalMutants: int,
     *     killedMutants: int,
     *     survivedMutants: int,
     *     notCoveredMutants: int,
     *     msi: float,
     *     coveredMsi: float
     * } - serialisable per-file row for report output; 'file' holds the display path, the counts are raw mutant tallies, and the two MSI values are
     * percentages (0-100).
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

<?php

declare(strict_types=1);

namespace GruffPhp\Results\Scoring;

/**
 * One file's score-report row, with its grade, finding counts, finding weight, and optional metrics.
 * ScoreCalculator supplies rows for files with findings or mutation results.
 *
 * Complexity and length maxima come from finding metadata; mutation scores come from per-file mutation results.
 */
final readonly class FileScore
{
    /**
     * Captures one file's grade, severity tallies, penalty, and optional peak metrics for the offenders list.
     *
     * @param string     $filePath - Display path of the scored file, as the user sees it in the report.
     * @param Grade|null $grade - Letter grade and score for the file, null when the run evaluated nothing.
     * @param int        $findings - Total findings counted against the file.
     * @param int        $advisory - Advisory-severity findings in the file.
     * @param int        $warning - Warning-severity findings in the file.
     * @param int        $error - Error-severity findings in the file.
     * @param float      $penalty - Sum of the file's finding weights used by the scoring curve.
     * @param int|null   $maxCyclomatic - Highest reported cyclomatic complexity; null when matching finding metadata has no integer value.
     * @param int|null   $maxCognitive - Highest reported cognitive complexity; null when matching finding metadata has no integer value.
     * @param int|null   $maxLines - Maximum reported file, class, or method length; null when no size finding has integer line metadata.
     * @param float|null $mutationScore - The file's mutation score; null when mutation analysis did not cover it.
     */
    public function __construct(
        public string $filePath,
        public ?Grade $grade,
        public int    $findings,
        public int    $advisory,
        public int    $warning,
        public int    $error,
        public float  $penalty,
        public ?int   $maxCyclomatic,
        public ?int   $maxCognitive,
        public ?int   $maxLines,
        public ?float $mutationScore,
    ) {
    }

    /**
     * Returns the report row used by score reporters, rounding the penalty to two decimals.
     * Unmeasured metrics remain null.
     *
     * @return array{
     *     file: string,
     *     score: float|null,
     *     grade: string|null,
     *     findings: int,
     *     advisory: int,
     *     warning: int,
     *     error: int,
     *     penalty: float,
     *     maxCyclomatic: int|null,
     *     maxCognitive: int|null,
     *     maxLines: int|null,
     *     mutationScore: float|null
     * } - the report row; null metric values mean "not measured", not zero.
     */
    public function toArray(): array
    {
        return [
            'file'          => $this->filePath,
            'score'         => $this->grade?->score,
            'grade'         => $this->grade?->letter,
            'findings'      => $this->findings,
            'advisory'      => $this->advisory,
            'warning'       => $this->warning,
            'error'         => $this->error,
            'penalty'       => round($this->penalty, 2),
            'maxCyclomatic' => $this->maxCyclomatic,
            'maxCognitive'  => $this->maxCognitive,
            'maxLines'      => $this->maxLines,
            'mutationScore' => $this->mutationScore,
        ];
    }
}

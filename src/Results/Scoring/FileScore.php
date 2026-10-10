<?php

declare(strict_types=1);

namespace GruffPhp\Results\Scoring;

/**
 * One file's line in the "worst offenders" list - its grade, finding counts, and the peak complexity
 * and mutation metrics that explain why it scored the way it did.
 *
 * When a user wants to know which files to fix first, gruff ranks them and shows this row for each: the
 * file's letter grade and score, how many findings of each severity it carries, the penalty they cost,
 * and - where measured - its worst cyclomatic and cognitive complexity, its longest method, and its
 * mutation score. It's the per-file detail sitting behind the run's composite grade.
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
     * @param float      $penalty - Total score penalty these findings cost the file.
     * @param int|null   $maxCyclomatic - Highest cyclomatic complexity found in the file; null when it was not measured for this run.
     * @param int|null   $maxCognitive - Highest cognitive complexity found in the file; null when it was not measured.
     * @param int|null   $maxLines - Longest method's line count in the file; null when it was not measured.
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
     * Flattens this score into the JSON-report row shape the reporters and dashboard consume.
     *
     * Keys are the wire contract: renaming one breaks every JSON/SARIF/HTML consumer, so treat the
     * shape as stable. The nested grade is expanded into separate `score`/`grade` keys and `penalty`
     * is rounded to 2 decimals so downstream diffs stay stable; nullable metric keys stay null when
     * the metric was not measured for this file.
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

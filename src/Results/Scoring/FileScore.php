<?php

declare(strict_types=1);

namespace GruffPhp\Results\Scoring;

/**
 * Carries score and finding totals for one source file.
 */
final readonly class FileScore
{
    /**
     * Capture score, finding totals, and optional metrics for one file.
     *
     * @param string     $filePath - Display path for the scored file.
     * @param Grade      $grade - Letter grade and numeric score for the file.
     * @param int        $findings - Total findings counted for the file.
     * @param int        $advisory - Advisory findings counted for the file.
     * @param int        $warning - Warning findings counted for the file.
     * @param int        $error - Error findings counted for the file.
     * @param float      $penalty - Score penalty applied to the file.
     * @param int|null   $maxCyclomatic - Highest cyclomatic complexity found in the file.
     * @param int|null   $maxCognitive - Highest cognitive complexity found in the file.
     * @param int|null   $maxLines - Highest method line count found in the file.
     * @param float|null $mutationScore - Mutation score for the file, when available.
     */
    public function __construct(
        public string $filePath,
        public Grade  $grade,
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
     * Flatten this score into the JSON-report row shape consumed by reporters and the dashboard.
     *
     * Keys are the wire contract: renaming one breaks every JSON/SARIF/HTML consumer, so treat the
     * shape as stable. The nested grade is expanded into separate `score`/`grade` keys and `penalty`
     * is rounded to 2 decimals so downstream diffs stay stable; nullable metric keys stay null when
     * the metric was not measured for this file.
     *
     * @return array{
     *     file: string,
     *     score: float,
     *     grade: string,
     *     findings: int,
     *     advisory: int,
     *     warning: int,
     *     error: int,
     *     penalty: float,
     *     maxCyclomatic: int|null,
     *     maxCognitive: int|null,
     *     maxLines: int|null,
     *     mutationScore: float|null
     * } - the report row; null metric values mean "not measured", not zero
     */
    public function toArray(): array
    {
        return [
            'file'          => $this->filePath,
            'score'         => $this->grade->score,
            'grade'         => $this->grade->letter,
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

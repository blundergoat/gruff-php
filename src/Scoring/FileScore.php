<?php

declare(strict_types=1);

namespace GruffPhp\Scoring;

/**
 * Carries score and finding totals for one source file.
 */
final readonly class FileScore
{
    /**
     * Capture score, finding totals, and optional metrics for one file.
     *
     * @param string $filePath Display path for the scored file.
     * @param Grade $grade Letter grade and numeric score for the file.
     * @param int $findings Total findings counted for the file.
     * @param int $advisories Advisory findings counted for the file.
     * @param int $warnings Warning findings counted for the file.
     * @param int $errors Error findings counted for the file.
     * @param float $penalty Score penalty applied to the file.
     * @param int|null $maxCyclomatic Highest cyclomatic complexity found in the file.
     * @param int|null $maxCognitive Highest cognitive complexity found in the file.
     * @param int|null $maxLines Highest method line count found in the file.
     * @param float|null $mutationScore Mutation score for the file, when available.
     */
    public function __construct(
        public string $filePath,
        public Grade $grade,
        public int $findings,
        public int $advisories,
        public int $warnings,
        public int $errors,
        public float $penalty,
        public ?int $maxCyclomatic,
        public ?int $maxCognitive,
        public ?int $maxLines,
        public ?float $mutationScore,
    ) {
    }

    /**
     * @return array{
     *     file: string,
     *     score: float,
     *     grade: string,
     *     findings: int,
     *     advisories: int,
     *     warnings: int,
     *     errors: int,
     *     penalty: float,
     *     maxCyclomatic: int|null,
     *     maxCognitive: int|null,
     *     maxLines: int|null,
     *     mutationScore: float|null
     * }
     */
    public function toArray(): array
    {
        return [
            'file' => $this->filePath,
            'score' => $this->grade->score,
            'grade' => $this->grade->letter,
            'findings' => $this->findings,
            'advisories' => $this->advisories,
            'warnings' => $this->warnings,
            'errors' => $this->errors,
            'penalty' => round($this->penalty, 2),
            'maxCyclomatic' => $this->maxCyclomatic,
            'maxCognitive' => $this->maxCognitive,
            'maxLines' => $this->maxLines,
            'mutationScore' => $this->mutationScore,
        ];
    }
}

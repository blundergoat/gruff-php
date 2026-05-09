<?php

declare(strict_types=1);

namespace GruffPhp\Scoring;

final readonly class FileScore
{
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

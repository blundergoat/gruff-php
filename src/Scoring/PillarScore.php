<?php

declare(strict_types=1);

namespace GruffPhp\Scoring;

/**
 * Carries score, grade, and finding totals for one quality pillar.
 */
final readonly class PillarScore
{
    /**
     * Capture score and finding totals for one quality pillar.
     */
    public function __construct(
        public string $pillar,
        public bool $applicable,
        public ?Grade $grade,
        public int $findings,
        public int $advisories,
        public int $warnings,
        public int $errors,
        public float $penalty,
    ) {
    }

    /**
     * @return array{
     *     pillar: string,
     *     applicable: bool,
     *     score: float|null,
     *     grade: string|null,
     *     findings: int,
     *     advisories: int,
     *     warnings: int,
     *     errors: int,
     *     penalty: float
     * }
     */
    public function toArray(): array
    {
        return [
            'pillar' => $this->pillar,
            'applicable' => $this->applicable,
            'score' => $this->grade?->score,
            'grade' => $this->grade?->letter,
            'findings' => $this->findings,
            'advisories' => $this->advisories,
            'warnings' => $this->warnings,
            'errors' => $this->errors,
            'penalty' => round($this->penalty, 2),
        ];
    }
}

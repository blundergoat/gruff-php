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
     *
     * @param string     $pillar     Pillar identifier represented by this score.
     * @param bool       $applicable Whether the pillar had any applicable inputs.
     * @param Grade|null $grade      Letter grade and numeric score, when applicable.
     * @param int        $findings   Total findings counted for the pillar.
     * @param int        $advisory   Advisory findings counted for the pillar.
     * @param int        $warning    Warning findings counted for the pillar.
     * @param int        $error      Error findings counted for the pillar.
     * @param float      $penalty    Score penalty applied to the pillar.
     */
    public function __construct(
        public string $pillar,
        public bool $applicable,
        public ?Grade $grade,
        public int $findings,
        public int $advisory,
        public int $warning,
        public int $error,
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
     *     advisory: int,
     *     warning: int,
     *     error: int,
     *     penalty: float
     * }
     */
    public function toArray(): array
    {
        // score and grade are null for an inapplicable pillar (no grade); penalty is rounded to match report precision.
        return [
            'pillar' => $this->pillar,
            'applicable' => $this->applicable,
            'score' => $this->grade?->score,
            'grade' => $this->grade?->letter,
            'findings' => $this->findings,
            'advisory' => $this->advisory,
            'warning' => $this->warning,
            'error' => $this->error,
            'penalty' => round($this->penalty, 2),
        ];
    }
}

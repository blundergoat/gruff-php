<?php

declare(strict_types=1);

namespace GruffPhp\Scoring;

final readonly class Grade
{
    /**
     * Create a grade from a numeric score and display letter.
     */
    public function __construct(
        public float $score,
        public string $letter,
    ) {
    }

    /**
     * Build a grade after clamping and rounding a score.
     *
     * @return self Grade for the normalised score.
     */
    public static function fromScore(float $score): self
    {
        $normalisedScore = max(0.0, min(100.0, round($score, 2)));

        return new self($normalisedScore, self::letterFor($normalisedScore));
    }

    /**
     * Resolve the letter grade for a numeric score.
     *
     * @return string Letter grade.
     */
    public static function letterFor(float $score): string
    {
        if ($score >= 90.0) {
            return 'A';
        }

        if ($score >= 80.0) {
            return 'B';
        }

        if ($score >= 70.0) {
            return 'C';
        }

        if ($score >= 60.0) {
            return 'D';
        }

        return 'F';
    }

    /**
     * @return array{score: float, grade: string}
     */
    public function toArray(): array
    {
        return [
            'score' => $this->score,
            'grade' => $this->letter,
        ];
    }
}

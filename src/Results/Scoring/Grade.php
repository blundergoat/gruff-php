<?php

declare(strict_types=1);

namespace GruffPhp\Results\Scoring;

/**
 * Converts numeric scores into letter grades for reports.
 */
final readonly class Grade
{
    /**
     * Create a grade from a numeric score and display letter.
     *
     * @param float  $score - Rounded numeric score.
     * @param string $letter - Display letter for the score.
     */
    public function __construct(
        public float  $score,
        public string $letter,
    ) {
    }

    /**
     * Build a grade after clamping and rounding a score.
     *
     * @param float $score - Raw score to clamp into the 0-100 range.
     *
     * @return self - grade carrying the score clamped to 0-100 and rounded to 2 decimals, plus its derived letter
     */
    public static function fromScore(float $score): self
    {
        $normalisedScore = max(0.0, min(100.0, round($score, 2)));

        return new self($normalisedScore, self::letterFor($normalisedScore));
    }

    /**
     * Resolve the letter grade for a numeric score.
     *
     * @param float $score - Normalized score to classify.
     *
     * @return string - single uppercase letter A-F by descending band; F for anything below 60
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
     * Serialize this value object into the array shape used by reports.
     *
     * @return array{score: float, grade: string} - report row with the numeric score and its letter under the `grade` key
     */
    public function toArray(): array
    {
        return [
            'score' => $this->score,
            'grade' => $this->letter,
        ];
    }
}

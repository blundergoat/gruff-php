<?php

declare(strict_types=1);

namespace GruffPhp\Scoring;

/**
 * Converts numeric scores into letter grades for reports.
 */
final readonly class Grade
{
    /**
     * Create a grade from a numeric score and display letter.
     *
     * @param float  $score  Rounded numeric score.
     * @param string $letter Display letter for the score.
     */
    public function __construct(
        public float  $score,
        public string $letter,
    ) {
    }

    /**
     * Build a grade after clamping and rounding a score.
     *
     * @param float $score Raw score to clamp into the 0-100 range.
     *
     * @return self - grade carrying the score clamped to 0-100 and rounded to 2 decimals, plus its derived letter
     */
    public static function fromScore(float $score): self
    {
        $normalisedScore = max(0.0, min(100.0, round($score, 2)));

        // Letter is always derived from the clamped score, never the raw input, so the two never disagree.
        return new self($normalisedScore, self::letterFor($normalisedScore));
    }

    /**
     * Resolve the letter grade for a numeric score.
     *
     * @param float $score Normalized score to classify.
     *
     * @return string - single uppercase letter A-F by descending band; F for anything below 60
     */
    public static function letterFor(float $score): string
    {
        if ($score >= 90.0) {
            // 90 and above is the top band; bounds are inclusive at the floor and open above.
            return 'A';
        }

        if ($score >= 80.0) {
            // 80-89.99 falls one band below A.
            return 'B';
        }

        if ($score >= 70.0) {
            // 70-79.99 is the middle passing band.
            return 'C';
        }

        if ($score >= 60.0) {
            // 60-69.99 is the lowest still-passing band.
            return 'D';
        }

        // Anything under 60 is the failing grade; this is the catch-all once every floor is missed.
        return 'F';
    }

    /**
     * Serialize this value object into the array shape used by reports.
     *
     * @return array{score: float, grade: string} - report row with the numeric score and its letter under the `grade` key
     */
    public function toArray(): array
    {
        // Report shape exposes the letter under the key `grade`, not `letter`, to match the documented schema.
        return [
            'score' => $this->score,
            'grade' => $this->letter,
        ];
    }
}

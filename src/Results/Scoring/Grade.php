<?php

declare(strict_types=1);

namespace GruffPhp\Results\Scoring;

/**
 * A single quality score paired with the letter grade a user reads at a glance (92.5 becomes "A").
 *
 * Raw scores are hard to skim, so every score gruff shows - the composite, each pillar, each file - is
 * wrapped here alongside its A-F letter. `fromScore()` builds one by clamping a raw score into 0-100
 * and picking the band, so reports, the dashboard, and JSON output all grade the same way and a user
 * never sees an out-of-range number or a letter that doesn't match its score.
 */
final readonly class Grade
{
    /**
     * Pairs an already-clamped score with its display letter; prefer fromScore() unless you already hold both.
     *
     * @param float  $score - Score already clamped to 0-100 and rounded to two decimals.
     * @param string $letter - Display letter (A-F) matching the score's band.
     */
    public function __construct(
        public float  $score,
        public string $letter,
    ) {
    }

    /**
     * Builds a grade from any raw score, clamping it into 0-100 first so a stray value can never show
     * the user a nonsensical number or letter.
     *
     * @param float $score - Raw score to clamp and grade.
     *
     * @return self - Grade carrying the score clamped to 0-100 and rounded to two decimals, plus its derived letter.
     */
    public static function fromScore(float $score): self
    {
        $normalisedScore = max(0.0, min(100.0, round($score, 2)));

        return new self($normalisedScore, self::letterFor($normalisedScore));
    }

    /**
     * Maps a normalised score to the A-F letter the user sees, by descending band.
     *
     * @param float $score - Normalised (0-100) score to classify.
     *
     * @return string - Single uppercase letter A-F by descending band; F for anything below 60.
     */
    public static function letterFor(float $score): string
    {
        // 90 and up is the top band, shown to the user as an "A".
        if ($score >= 90.0) {
            return 'A';
        }

        // The 80s earn a "B" - solid, with a little room to improve.
        if ($score >= 80.0) {
            return 'B';
        }

        // The 70s are a middling "C".
        if ($score >= 70.0) {
            return 'C';
        }

        // The 60s scrape a passing "D".
        if ($score >= 60.0) {
            return 'D';
        }

        // Anything under 60 is a failing "F" - the code the user most needs to look at.
        return 'F';
    }

    /**
     * Flattens the grade into the `{score, grade}` row that reports and the dashboard serialise.
     *
     * @return array{score: float, grade: string} - Report row: the numeric score plus its letter under the `grade` key.
     */
    public function toArray(): array
    {
        return [
            'score' => $this->score,
            'grade' => $this->letter,
        ];
    }
}

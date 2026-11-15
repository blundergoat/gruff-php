<?php

declare(strict_types=1);

namespace GruffPhp\Results\Scoring;

/**
 * Pairs a numeric quality score with the letter shown in score reports.
 * fromScore() rounds and clamps its input before selecting a letter.
 *
 * The public constructor stores supplied values directly and does not enforce that they match.
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
     * Returns the score and grade fields used by reports and the dashboard.
     *
     * @return array{score: float, grade: string} - Report row containing the numeric score and its letter in the grade field.
     */
    public function toArray(): array
    {
        return [
            'score' => $this->score,
            'grade' => $this->letter,
        ];
    }
}

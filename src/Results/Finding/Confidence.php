<?php

declare(strict_types=1);

namespace GruffPhp\Results\Finding;

/**
 * Describes how certain a rule is about a finding.
 *
 * Reports expose low, medium, and high so reviewers can weigh the signal.
 * The `--min-confidence` gate can limit which findings cause a nonzero exit.
 */
enum Confidence: string
{
    /**
     * Best-guess signal with real false-positive risk - the user should sanity-check it before acting.
     */
    case Low = 'low';

    /**
     * Moderately reliable signal - usually right, but not beyond a second look.
     */
    case Medium = 'medium';

    /**
     * Signal expected to hold in normal use, so the user can generally trust it at face value.
     */
    case High = 'high';

    /**
     * Orders confidence from the lowest to the highest gate threshold.
     *
     * @return int - Rank from zero to two.
     */
    public function rank(): int
    {
        return match ($this) {
            self::Low => 0,
            self::Medium => 1,
            self::High => 2,
        };
    }
}

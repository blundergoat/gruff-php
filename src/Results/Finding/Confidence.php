<?php

declare(strict_types=1);

namespace GruffPhp\Results\Finding;

/**
 * How much to trust a rule's signal - the dial between "definitely a problem" and "worth a look".
 *
 * Heuristic rules can misfire, so each finding carries a confidence the user can weigh: it colours how
 * the finding is presented and lets someone tune out the lower-confidence noise when they only want the
 * near-certain issues. Higher confidence means fewer false positives in normal use, so this is what
 * separates a finding a user can act on blind from one they may want to eyeball first.
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
}

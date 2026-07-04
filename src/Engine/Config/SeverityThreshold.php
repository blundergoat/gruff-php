<?php

declare(strict_types=1);

namespace GruffPhp\Engine\Config;

use GruffPhp\Results\Finding\Severity;

/**
 * One rung of a rule's severity ladder - the measured value at which a finding jumps to a given severity.
 *
 * Rules like complexity or method length do not fire at a single cutoff; they escalate. A user's config
 * turns into a set of these, so a method just over the line reads as advisory while a badly bloated one
 * reads as an error. This pairs the number with the severity it triggers.
 */
final readonly class SeverityThreshold
{
    /**
     * Pairs a numeric cutoff with the severity a value at or past it should carry.
     *
     * @param int|float $threshold - The measured value at which this severity starts to apply.
     * @param Severity  $severity - Severity emitted once a measurement reaches the threshold.
     */
    public function __construct(
        public int|float $threshold,
        public Severity $severity,
    ) {
    }
}

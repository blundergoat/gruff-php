<?php

declare(strict_types=1);

namespace GruffPhp\Engine\Config;

use GruffPhp\Results\Finding\Severity;

/**
 * Couples a severity with the numeric threshold that activates it.
 */
final readonly class SeverityThreshold
{
    /**
     * Pair a numeric threshold with the severity it should emit.
     *
     * @param int|float $threshold - Numeric threshold value.
     * @param Severity  $severity - Severity emitted when the threshold matches.
     */
    public function __construct(
        public int|float $threshold,
        public Severity $severity,
    ) {
    }
}

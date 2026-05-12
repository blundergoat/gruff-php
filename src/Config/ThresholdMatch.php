<?php

declare(strict_types=1);

namespace GruffPhp\Config;

use GruffPhp\Finding\Severity;

/**
 * Records the severity threshold matched by a measured rule value.
 */
final readonly class ThresholdMatch
{
    /**
     * Capture the threshold and severity selected for a measured value.
     */
    public function __construct(
        public int|float $threshold,
        public Severity $severity,
    ) {
    }
}

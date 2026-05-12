<?php

declare(strict_types=1);

namespace GruffPhp\Config;

use GruffPhp\Finding\Severity;

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

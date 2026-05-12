<?php

declare(strict_types=1);

namespace GruffPhp\Config;

use GruffPhp\Finding\Severity;

final readonly class SeverityThreshold
{
    /**
     * Pair a numeric threshold with the severity it should emit.
     */
    public function __construct(
        public int|float $threshold,
        public Severity $severity,
    ) {
    }
}

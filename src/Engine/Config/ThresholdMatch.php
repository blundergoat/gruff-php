<?php

declare(strict_types=1);

namespace GruffPhp\Engine\Config;

use GruffPhp\Results\Finding\Severity;

/**
 * Records the severity threshold matched by a measured rule value.
 */
final readonly class ThresholdMatch
{
    /**
     * Capture the threshold and severity selected for a measured value.
     *
      * User flow: Turns project settings into the analysis run the user requested.
      *
     * @param int|float $threshold - Threshold value that matched.
     * @param Severity  $severity - Severity assigned to the threshold.
     */
    public function __construct(
        public int|float $threshold,
        public Severity $severity,
    ) {
    }
}

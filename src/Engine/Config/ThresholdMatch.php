<?php

declare(strict_types=1);

namespace GruffPhp\Engine\Config;

use GruffPhp\Results\Finding\Severity;

/**
 * The verdict of checking a measured value against a rule's severity ladder - which threshold it hit
 * and the severity that earns.
 *
 * When a rule measures something (a method's length, a class's complexity) it looks the value up
 * against the configured thresholds; this records the rung that matched, so the finding is stamped with
 * the right severity and the number that justified it.
 */
final readonly class ThresholdMatch
{
    /**
     * Captures the threshold a measured value matched and the severity it earns.
     *
     * @param int|float $threshold - Threshold value the measurement met or exceeded.
     * @param Severity  $severity - Severity assigned because that threshold matched.
     */
    public function __construct(
        public int|float $threshold,
        public Severity $severity,
    ) {
    }
}

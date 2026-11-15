<?php

declare(strict_types=1);

namespace GruffPhp\Engine\Config;

use GruffPhp\Results\Finding\Severity;

/**
 * Pairs a numeric threshold with the severity assigned when a measurement breaches it.
 * Used by RuleSettings for a single-threshold override in place of warning/error thresholds.
 *
 * Higher-is-worse measurements must exceed the threshold; lower-is-worse measurements must fall below it.
 */
final readonly class SeverityThreshold
{
    /**
     * Stores the numeric boundary and severity used when a measurement breaches that boundary.
     *
     * @param int|float $threshold - Boundary compared strictly in the direction chosen by the rule.
     * @param Severity  $severity - Severity emitted when that boundary is breached.
     */
    public function __construct(
        public int|float $threshold,
        public Severity $severity,
    ) {
    }
}

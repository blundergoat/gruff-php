<?php

declare(strict_types=1);

namespace GruffPhp\Diff;

use GruffPhp\Finding\Finding;

/**
 * Carries findings retained by changed-region filtering plus the suppression count.
 */
final readonly class DiffFilterResult
{
    /**
     * @param list<Finding> $findings - Findings retained in the changed scope.
     * @param int           $suppressedCount - Findings excluded as out of scope.
     */
    public function __construct(
        public array $findings,
        public int $suppressedCount,
    ) {
    }
}

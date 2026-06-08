<?php

declare(strict_types=1);

namespace GruffPhp\Hook;

use GruffPhp\Finding\Finding;

/**
 * Result of applying hook changed-region and new-only filtering.
 */
final readonly class HookFilterResult
{
    /**
     * @param list<Finding> $findings        - Findings kept for hook output.
     * @param int           $suppressedCount - Findings removed by hook filtering.
     */
    public function __construct(
        public array $findings,
        public int $suppressedCount,
    ) {
    }
}

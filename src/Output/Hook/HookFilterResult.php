<?php

declare(strict_types=1);

namespace GruffPhp\Output\Hook;

use GruffPhp\Results\Finding\Finding;

/**
 * Result of applying hook changed-region and new-only filtering.
 */
final readonly class HookFilterResult
{
    /**
      * User flow: Shapes hook feedback before a developer continues their workflow.
      *
     * @param list<Finding>      $findings        - Findings kept for hook output.
     * @param int                $suppressedCount - Findings removed by hook filtering.
     * @param array<int, string> $identities      - Disambiguated hook identity keyed by spl_object_id($finding), spanning the full input set.
     */
    public function __construct(
        public array $findings,
        public int $suppressedCount,
        public array $identities = [],
    ) {
    }
}
